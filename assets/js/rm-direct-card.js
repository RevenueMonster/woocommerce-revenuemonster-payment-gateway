/* global jQuery, rmDirectCard, wc_checkout_params */
/**
 * Revenue Monster - Direct Card Checkout for the classic [woocommerce_checkout].
 *
 * process_payment() returns an `rm_card` handle; this script POSTs the card
 * details straight to Revenue Monster, runs 3-D Secure in a modal, then polls
 * rm_direct_card_status for the authoritative result (a 3DS challenge alone
 * never marks the order paid).
 *
 * Card inputs have no `name`, so card data never enters the checkout POST, and
 * they are wiped from the DOM once handed to Revenue Monster. Nothing is logged.
 */
(function ($) {
	'use strict';

	var state = { submitting: false };

	function i18n(k) {
		return (rmDirectCard.i18n && rmDirectCard.i18n[k]) || '';
	}

	function $fields() {
		return $('#rm-direct-card-fields');
	}

	function hasCardFields() {
		return $fields().length > 0 && $('#rm-card-number').length > 0;
	}

	// "card" = native card form (client-side flow); "hosted" = redirect to the
	// Revenue Monster page. Only meaningful when Direct Card mode is on.
	function currentPayMode() {
		return $('input[name="rm_pay_mode"]:checked').val() === 'hosted' ? 'hosted' : 'card';
	}

	function syncPayMode() {
		if (!hasCardFields()) {
			return;
		}
		var card = currentPayMode() === 'card';
		$fields().toggle(card);
		$('#rm-card-number, #rm-card-name, #rm-card-expiry, #rm-card-cvv').prop('disabled', !card);
		if (!card) {
			notice('');
		}
	}

	function digits(v) {
		return String(v || '').replace(/\D/g, '');
	}

	/* --- validation ------------------------------------------------------- */

	function luhn(num) {
		var sum = 0,
			flip = false;
		for (var i = num.length - 1; i >= 0; i--) {
			var d = parseInt(num.charAt(i), 10);
			if (flip) {
				d *= 2;
				if (d > 9) {
					d -= 9;
				}
			}
			sum += d;
			flip = !flip;
		}
		return sum % 10 === 0;
	}

	function readCard() {
		// Combined "MM / YY" (or "MM / YYYY") field.
		var exp = digits($('#rm-card-expiry').val());
		return {
			no: digits($('#rm-card-number').val()),
			name: $.trim($('#rm-card-name').val() || ''),
			month: exp.length >= 2 ? parseInt(exp.slice(0, 2), 10) : NaN,
			year: exp.length >= 4 ? parseInt(exp.slice(2), 10) : NaN,
			cvv: digits($('#rm-card-cvv').val())
		};
	}

	function validate() {
		var c = readCard();

		if (c.no.length < 12 || c.no.length > 19 || !luhn(c.no)) {
			return { ok: false, message: i18n('invalidNumber') };
		}
		if (!c.name) {
			return { ok: false, message: i18n('invalidName') };
		}
		if (!c.month || c.month < 1 || c.month > 12) {
			return { ok: false, message: i18n('invalidExpiry') };
		}
		var year = c.year < 100 ? 2000 + c.year : c.year;
		var now = new Date();
		if (!year || year < now.getFullYear() || year > now.getFullYear() + 25) {
			return { ok: false, message: i18n('invalidExpiry') };
		}
		if (year === now.getFullYear() && c.month < now.getMonth() + 1) {
			return { ok: false, message: i18n('invalidExpiry') };
		}
		if (c.cvv.length < 3 || c.cvv.length > 4) {
			return { ok: false, message: i18n('invalidCvv') };
		}
		return { ok: true };
	}

	function notice(msg) {
		var $n = $fields().find('.rm-direct-card__notice');
		if (msg) {
			$n.text(msg).show();
		} else {
			$n.hide().text('');
		}
	}

	/* --- WooCommerce order placement ------------------------------------- */

	function block($form) {
		$form.addClass('processing').block({
			message: null,
			overlayCSS: { background: '#fff', opacity: 0.6 }
		});
	}

	function unblock($form) {
		$form.removeClass('processing').unblock();
		state.submitting = false;
	}

	function decodeRedirect(url) {
		return String(url || '').indexOf('%') !== -1 ? decodeURI(url) : url;
	}

	function showWcErrors(result, $form) {
		$(
			'.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message'
		).remove();
		if (result && result.messages) {
			$form.prepend(
				'<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' +
					result.messages +
					'</div>'
			);
		} else {
			notice(i18n('generic'));
		}
		unblock($form);
		$(document.body).trigger('checkout_error', [result && result.messages]);
		$('html, body').animate(
			{ scrollTop: $form.offset().top - 100 },
			400
		);
	}

	function placeOrder($form) {
		block($form);
		notice('');

		$.ajax({
			type: 'POST',
			url: wc_checkout_params.checkout_url,
			data: $form.serialize(),
			dataType: 'json'
		})
			.done(function (result) {
				try {
					if (!result) {
						throw new Error('empty');
					}
					if (result.result === 'success' && result.rm_card) {
						submitCard(result.rm_card, $form);
					} else if (result.result === 'success') {
						window.location = decodeRedirect(result.redirect);
					} else if (result.reload) {
						window.location.reload();
					} else {
						showWcErrors(result, $form);
					}
				} catch (e) {
					showWcErrors(null, $form);
				}
			})
			.fail(function () {
				showWcErrors(null, $form);
			});
	}

	/* --- Step 3: submit card straight to Revenue Monster ---------------- */

	function clearCardInputs() {
		$('#rm-card-number, #rm-card-name, #rm-card-expiry, #rm-card-cvv')
			.val('')
			.trigger('change');
	}

	function submitCard(rm, $form) {
		var c = readCard();
		clearCardInputs();

		// Remember the active transaction so the modal's Close button can abort.
		state.rm = rm;
		state.$form = $form;
		state.cancelled = false;

		var payload = {
			code: rm.code,
			type: 'URL',
			card: {
				no: c.no,
				name: c.name,
				expiryMonth: c.month,
				expiryYear: c.year < 100 ? 2000 + c.year : c.year,
				securityCode: c.cvv
			}
		};

		fetch(rm.endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		})
			.then(function (r) {
				return r.json().catch(function () {
					return null;
				});
			})
			.then(function (res) {
				payload = null;
				c = null;

				if (!res || res.code !== 'SUCCESS' || !res.item) {
					return failCard(
						rm,
						$form,
						(res && res.error && res.error.message) || i18n('generic')
					);
				}

				var item = res.item;
				if (item.action === '3DS_CHALLENGE' && item.url) {
					open3ds(item.url, rm, $form);
				} else {
					// Non-3DS / frictionless: let the real status settle the order.
					show3dsShell($form);
					pollStatus(rm, $form);
				}
			})
			.catch(function () {
				failCard(rm, $form, i18n('generic'));
			});
	}

	function failCard(rm, $form, message) {
		close3ds();
		unblock($form);
		notice(message || i18n('generic'));
		$('html, body').animate({ scrollTop: $fields().offset().top - 120 }, 300);
	}

	/* --- 3DS modal ----------------------------------------------------- */

	function show3dsShell($form) {
		if ($('#rm-3ds-overlay').length) {
			return;
		}
		var cancelLabel = i18n('cancel') || 'Cancel payment';
		var html =
			'<div id="rm-3ds-overlay" class="rm-3ds-overlay" role="dialog" aria-modal="true" aria-label="3-D Secure authentication">' +
			'<div class="rm-3ds-modal">' +
			'<div class="rm-3ds-head"><span class="rm-3ds-title">' +
			i18n('authInProgress') +
			'</span><button type="button" class="rm-3ds-close" aria-label="' +
			cancelLabel +
			'" title="' +
			cancelLabel +
			'">&times;</button></div>' +
			'<div class="rm-3ds-body"><div class="rm-3ds-spinner"></div>' +
			'<div class="rm-3ds-frame-wrap"></div></div>' +
			'<div class="rm-3ds-foot"></div>' +
			'</div></div>';
		$('body').append(html);
		$('body').addClass('rm-3ds-open');
		$('#rm-3ds-overlay .rm-3ds-close').on('click', function () {
			cancelPayment($form);
		});
	}

	// Navigate, forcing a reload when the target is the current page (otherwise
	// assigning the same URL is a no-op and the customer looks "stuck").
	function navigate(url) {
		url = decodeRedirect(url || (state.rm && state.rm.failUrl) || window.location.href);
		if (url.split('#')[0] === window.location.href.split('#')[0]) {
			window.location.reload();
		} else {
			window.location.assign(url);
		}
	}

	/**
	 * Abort the in-flight card payment: stop polling, tell the server to cancel
	 * the order, then send the customer back to checkout. The cart is still
	 * intact in Direct Card mode, so they can retry immediately.
	 */
	function cancelPayment($form) {
		if (state.cancelling) {
			return;
		}
		state.cancelling = true;
		state.cancelled = true; // stops pollStatus()

		$('#rm-3ds-overlay .rm-3ds-close').prop('disabled', true);
		$('#rm-3ds-overlay .rm-3ds-title').text(i18n('cancelled'));
		$('#rm-3ds-overlay .rm-3ds-spinner').show();

		try {
			window.sessionStorage.setItem('rmDirectCardCancelled', '1');
		} catch (e) {
			/* ignore */
		}

		var rm = state.rm || {};
		var done = function (res) {
			close3ds();
			unblock($form || state.$form || $('form.checkout'));
			var data = (res && res.data) || {};
			navigate(data.redirect || rm.failUrl);
		};

		if (!rm.oid || !rm.key) {
			done();
			return;
		}

		$.ajax({
			type: 'POST',
			url: rmDirectCard.ajaxUrl,
			data: {
				action: 'rm_direct_card_status',
				op: 'cancel',
				nonce: rmDirectCard.nonce,
				oid: rm.oid,
				key: rm.key
			},
			dataType: 'json'
		})
			.done(done)
			.fail(function () {
				done();
			});
	}

	function open3ds(url, rm, $form) {
		show3dsShell($form);

		var $wrap = $('#rm-3ds-overlay .rm-3ds-frame-wrap');
		var $frame = $('<iframe>', {
			src: url,
			title: '3-D Secure authentication',
			frameborder: '0',
			allow: 'payment'
		});
		$frame.on('load', function () {
			$('#rm-3ds-overlay .rm-3ds-spinner').hide();
			try {
				// Same-origin means the challenge finished and RM redirected
				// back to our return / webhook URL inside the frame.
				var href = $frame[0].contentWindow.location.href;
				if (href && href.indexOf(window.location.origin) === 0) {
					finish(rm, href);
				}
			} catch (e) {
				/* cross-origin during the challenge: expected, ignore */
			}
		});
		$wrap.append($frame);

		pollStatus(rm, $form);
	}

	function close3ds() {
		$('#rm-3ds-overlay').remove();
		$('body').removeClass('rm-3ds-open');
	}

	function finish(rm, redirect) {
		close3ds();
		navigate(redirect || rm.returnUrl);
	}

	/* --- authoritative status polling -------------------------------- */

	function pollStatus(rm, $form) {
		var tries = 0;
		var max = 120; // ~6 minutes at 3s.

		(function tick() {
			if (state.cancelled) {
				return;
			}
			tries++;
			$.ajax({
				type: 'POST',
				url: rmDirectCard.ajaxUrl,
				data: {
					action: 'rm_direct_card_status',
					nonce: rmDirectCard.nonce,
					oid: rm.oid,
					key: rm.key
				},
				dataType: 'json'
			})
				.done(function (res) {
					if (state.cancelled) {
						return;
					}
					var data = res && res.data ? res.data : {};
					if (data.status === 'success') {
						finish(rm, data.redirect || rm.returnUrl);
						return;
					}
					if (data.status === 'failed') {
						close3ds();
						unblock($form);
						navigate(data.redirect || rm.failUrl);
						return;
					}
					if (tries >= max) {
						timedOut(rm);
						return;
					}
					setTimeout(tick, 3000);
				})
				.fail(function () {
					if (tries >= max) {
						timedOut(rm);
						return;
					}
					setTimeout(tick, 4000);
				});
		})();
	}

	function timedOut(rm) {
		var $foot = $('#rm-3ds-overlay .rm-3ds-foot');
		if ($foot.length && !$foot.find('.rm-3ds-return').length) {
			$('#rm-3ds-overlay .rm-3ds-title').text(i18n('generic'));
			$('<a class="rm-3ds-return" href="' + rm.failUrl + '">' + (i18n('cancel') || 'Cancel payment') + '</a>').appendTo(
				$foot
			);
		}
	}

	/* --- lightweight input formatting ------------------------------- */

	function bindFormatting() {
		var $exp = $('#rm-card-expiry');
		if ($exp.length && !$exp.data('rmFmt')) {
			$exp.data('rmFmt', 1).on('input', function () {
				var raw = digits(this.value);
				var mm = raw.slice(0, 2);
				var yy = raw.slice(2, 6);
				if (yy.length > 2) {
					yy = yy.slice(-2); // autofill "2028" -> "28"
				}
				var d = (mm + yy).slice(0, 4);
				if (d.length === 1 && parseInt(d, 10) > 1) {
					d = '0' + d; // "3" -> "03"
				}
				this.value = d.length > 2 ? d.slice(0, 2) + ' / ' + d.slice(2) : d;
			});
		}

		var $num = $('#rm-card-number');
		if ($num.length && !$num.data('rmFmt')) {
			$num.data('rmFmt', 1).on('input', function () {
				var d = digits(this.value).slice(0, 19);
				this.value = d.replace(/(.{4})/g, '$1 ').trim();
			});
		}
	}

	/* --- wiring ------------------------------------------------------ */

	$(function () {
		bindFormatting();
		syncPayMode();
		// Fields are re-rendered on every checkout AJAX refresh.
		$(document.body).on('updated_checkout payment_method_selected', function () {
			bindFormatting();
			syncPayMode();
		});
		$(document.body).on('change', 'input[name="rm_pay_mode"]', syncPayMode);

		// Surface a notice once, after a cancelled payment redirects back here.
		try {
			if (window.sessionStorage.getItem('rmDirectCardCancelled')) {
				window.sessionStorage.removeItem('rmDirectCardCancelled');
				if (hasCardFields()) {
					notice(i18n('cancelled'));
				}
			}
		} catch (e) {
			/* ignore */
		}

		// WooCommerce fires this via triggerHandler() on the checkout <form>
		// itself, and triggerHandler does not bubble - so the handler must be
		// bound directly on form.checkout, not on document.body.
		$('form.checkout').on('checkout_place_order_revenuemonster', function () {
			if (!hasCardFields() || currentPayMode() !== 'card') {
				return true; // Hosted mode, or customer chose E-wallet / Online Banking.
			}
			if (state.submitting) {
				return false;
			}

			var v = validate();
			if (!v.ok) {
				notice(v.message);
				return false;
			}

			state.submitting = true;
			placeOrder($('form.checkout'));
			return false; // We drive the request ourselves.
		});
	});
})(jQuery);
