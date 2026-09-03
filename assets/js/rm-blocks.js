/* global wp */
/**
 * Revenue Monster - Cart & Checkout Blocks payment method.
 *
 * Hosted mode shows title + description and lets the block use the server
 * redirect. Direct Card mode adds a "Card" / "E-wallet / Online Banking" choice:
 * onPaymentSetup hands rm_pay_mode to the Store API and validates the card;
 * onCheckoutSuccess picks up the rm_card_* handle, POSTs the card details
 * straight to Revenue Monster, runs 3-D Secure in a modal, and polls
 * rm_direct_card_status for the authoritative result.
 *
 * Card inputs have no `name` and are read via refs, so card data never enters
 * the checkout request and never reaches this server; values are wiped once
 * handed to Revenue Monster. Nothing is logged. Shared logic with
 * rm-direct-card.js is intentionally duplicated rather than extracted.
 */
( function () {
	'use strict';

	var wc = window.wc || {};
	var registry = wc.wcBlocksRegistry;
	var wcSettings = wc.wcSettings;
	var element = window.wp && window.wp.element;
	var htmlEntities = window.wp && window.wp.htmlEntities;

	if ( ! registry || ! registry.registerPaymentMethod || ! wcSettings || ! element ) {
		return;
	}

	var el = element.createElement;
	var useState = element.useState;
	var useRef = element.useRef;
	var useEffect = element.useEffect;

	var data = wcSettings.getSetting( 'revenuemonster_data', {} );
	var decode = htmlEntities && htmlEntities.decodeEntities
		? htmlEntities.decodeEntities
		: function ( v ) { return v; };

	var title = decode( data.title || 'RevenueMonster' );
	var description = decode( data.description || '' );
	var DIRECT = !! data.directCard;
	var I18N = data.i18n || {};

	function t( key, fallback ) {
		return I18N[ key ] || fallback || '';
	}

	// Tracks the in-flight card payment so the 3DS modal's close button and the
	// status poller can talk to each other.
	var flow = {};

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	function digits( v ) {
		return String( v || '' ).replace( /\D/g, '' );
	}

	function encodeForm( obj ) {
		return Object.keys( obj )
			.map( function ( k ) {
				return encodeURIComponent( k ) + '=' + encodeURIComponent( obj[ k ] );
			} )
			.join( '&' );
	}

	// Navigate, forcing a reload when the target is the current page (assigning
	// the same URL is a no-op and the customer looks "stuck").
	function navigate( url ) {
		url = url || window.location.href;
		if ( url.indexOf( '%' ) !== -1 ) {
			try { url = decodeURI( url ); } catch ( e ) { /* keep as-is */ }
		}
		if ( url.split( '#' )[ 0 ] === window.location.href.split( '#' )[ 0 ] ) {
			window.location.reload();
		} else {
			window.location.assign( url );
		}
	}

	/* --- validation (ported from rm-direct-card.js) -------------------- */

	function luhn( num ) {
		var sum = 0,
			flip = false;
		for ( var i = num.length - 1; i >= 0; i-- ) {
			var d = parseInt( num.charAt( i ), 10 );
			if ( flip ) {
				d *= 2;
				if ( d > 9 ) {
					d -= 9;
				}
			}
			sum += d;
			flip = ! flip;
		}
		return sum % 10 === 0;
	}

	function readCard( refs ) {
		var exp = digits( refs.expiry.current ? refs.expiry.current.value : '' );
		return {
			no: digits( refs.number.current ? refs.number.current.value : '' ),
			name: ( refs.name.current ? refs.name.current.value : '' ).replace( /^\s+|\s+$/g, '' ),
			month: exp.length >= 2 ? parseInt( exp.slice( 0, 2 ), 10 ) : NaN,
			year: exp.length >= 4 ? parseInt( exp.slice( 2 ), 10 ) : NaN,
			cvv: digits( refs.cvv.current ? refs.cvv.current.value : '' )
		};
	}

	function validateCard( c ) {
		if ( c.no.length < 12 || c.no.length > 19 || ! luhn( c.no ) ) {
			return { ok: false, message: t( 'invalidNumber' ) };
		}
		if ( ! c.name ) {
			return { ok: false, message: t( 'invalidName' ) };
		}
		if ( ! c.month || c.month < 1 || c.month > 12 ) {
			return { ok: false, message: t( 'invalidExpiry' ) };
		}
		var year = c.year < 100 ? 2000 + c.year : c.year;
		var now = new Date();
		if ( ! year || year < now.getFullYear() || year > now.getFullYear() + 25 ) {
			return { ok: false, message: t( 'invalidExpiry' ) };
		}
		if ( year === now.getFullYear() && c.month < now.getMonth() + 1 ) {
			return { ok: false, message: t( 'invalidExpiry' ) };
		}
		if ( c.cvv.length < 3 || c.cvv.length > 4 ) {
			return { ok: false, message: t( 'invalidCvv' ) };
		}
		return { ok: true };
	}

	function clearCard( refs ) {
		[ refs.name, refs.number, refs.expiry, refs.cvv ].forEach( function ( r ) {
			if ( r.current ) {
				r.current.value = '';
			}
		} );
	}

	/* --- lightweight input formatting -------------------------------- */

	function formatNumber( e ) {
		var d = digits( e.target.value ).slice( 0, 19 );
		e.target.value = d.replace( /(.{4})/g, '$1 ' ).replace( /\s+$/, '' );
	}

	function formatExpiry( e ) {
		var raw = digits( e.target.value );
		var mm = raw.slice( 0, 2 );
		var yy = raw.slice( 2, 6 );
		if ( yy.length > 2 ) {
			yy = yy.slice( -2 );
		}
		var v = ( mm + yy ).slice( 0, 4 );
		if ( v.length === 1 && parseInt( v, 10 ) > 1 ) {
			v = '0' + v;
		}
		e.target.value = v.length > 2 ? v.slice( 0, 2 ) + ' / ' + v.slice( 2 ) : v;
	}

	/* ------------------------------------------------------------------ *
	 * Step 3: submit card straight to Revenue Monster
	 * ------------------------------------------------------------------ */

	function submitCardToRm( handle, card ) {
		var payload = {
			code: handle.code,
			type: 'URL',
			card: {
				no: card.no,
				name: card.name,
				expiryMonth: card.month,
				expiryYear: card.year < 100 ? 2000 + card.year : card.year,
				securityCode: card.cvv
			}
		};

		return fetch( handle.endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( payload )
		} )
			.then( function ( r ) {
				return r.json().catch( function () { return null; } );
			} )
			.then( function ( res ) {
				payload = null;
				if ( ! res || res.code !== 'SUCCESS' || ! res.item ) {
					var msg = ( res && res.error && res.error.message ) || t( 'generic' );
					throw new Error( msg );
				}
				return res.item;
			} );
	}

	/* ------------------------------------------------------------------ *
	 * 3-D Secure modal
	 * ------------------------------------------------------------------ */

	function show3dsShell() {
		if ( document.getElementById( 'rm-3ds-overlay' ) ) {
			return;
		}
		var cancelLabel = t( 'cancel' ) || 'Cancel payment';

		var overlay = document.createElement( 'div' );
		overlay.id = 'rm-3ds-overlay';
		overlay.className = 'rm-3ds-overlay';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.setAttribute( 'aria-label', '3-D Secure authentication' );
		overlay.innerHTML =
			'<div class="rm-3ds-modal">' +
			'<div class="rm-3ds-head"><span class="rm-3ds-title"></span>' +
			'<button type="button" class="rm-3ds-close">&times;</button></div>' +
			'<div class="rm-3ds-body"><div class="rm-3ds-spinner"></div>' +
			'<div class="rm-3ds-frame-wrap"></div></div>' +
			'<div class="rm-3ds-foot"></div>' +
			'</div>';

		// Set translated text via textContent, never innerHTML.
		overlay.querySelector( '.rm-3ds-title' ).textContent = t( 'authInProgress' );
		var closeBtn = overlay.querySelector( '.rm-3ds-close' );
		closeBtn.setAttribute( 'aria-label', cancelLabel );
		closeBtn.setAttribute( 'title', cancelLabel );

		document.body.appendChild( overlay );
		document.body.classList.add( 'rm-3ds-open' );

		closeBtn.addEventListener( 'click', function () {
			cancelPayment();
		} );
	}

	function open3ds( url ) {
		show3dsShell();

		var wrap = document.querySelector( '#rm-3ds-overlay .rm-3ds-frame-wrap' );
		if ( ! wrap ) {
			return;
		}

		var frame = document.createElement( 'iframe' );
		frame.src = url;
		frame.title = '3-D Secure authentication';
		frame.setAttribute( 'frameborder', '0' );
		frame.setAttribute( 'allow', 'payment' );
		frame.addEventListener( 'load', function () {
			var spinner = document.querySelector( '#rm-3ds-overlay .rm-3ds-spinner' );
			if ( spinner ) {
				spinner.style.display = 'none';
			}
			try {
				// Same-origin means the challenge finished and RM redirected back
				// to our return / webhook URL inside the frame. The status poll is
				// the source of truth, so just drop the modal and let it settle.
				var href = frame.contentWindow.location.href;
				if ( href && href.indexOf( window.location.origin ) === 0 ) {
					close3ds();
				}
			} catch ( e ) {
				/* cross-origin during the challenge: expected, ignore */
			}
		} );
		wrap.appendChild( frame );
	}

	function close3ds() {
		var o = document.getElementById( 'rm-3ds-overlay' );
		if ( o && o.parentNode ) {
			o.parentNode.removeChild( o );
		}
		document.body.classList.remove( 'rm-3ds-open' );
	}

	/* ------------------------------------------------------------------ *
	 * Authoritative status polling (shared rm_direct_card_status endpoint)
	 * ------------------------------------------------------------------ */

	function pollStatus( handle ) {
		return new Promise( function ( resolve ) {
			var tries = 0;
			var max = 120; // ~6 minutes at 3s.

			( function tick() {
				if ( flow.cancelled ) {
					return;
				}
				tries++;
				fetch( data.ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					credentials: 'same-origin',
					body: encodeForm( {
						action: 'rm_direct_card_status',
						nonce: data.nonce,
						oid: handle.oid,
						key: handle.key
					} )
				} )
					.then( function ( r ) {
						return r.json().catch( function () { return null; } );
					} )
					.then( function ( res ) {
						if ( flow.cancelled ) {
							return;
						}
						var d = ( res && res.data ) || {};
						if ( d.status === 'success' ) {
							resolve( { status: 'success', redirect: d.redirect || handle.returnUrl } );
							return;
						}
						if ( d.status === 'failed' ) {
							resolve( { status: 'failed', redirect: d.redirect || handle.failUrl } );
							return;
						}
						if ( tries >= max ) {
							resolve( { status: 'failed', redirect: handle.failUrl } );
							return;
						}
						setTimeout( tick, 3000 );
					} )
					.catch( function () {
						if ( tries >= max ) {
							resolve( { status: 'failed', redirect: handle.failUrl } );
							return;
						}
						setTimeout( tick, 4000 );
					} );
			} )();
		} );
	}

	/**
	 * Abort the in-flight card payment: stop polling, tell the server to cancel
	 * the order, then resolve the flow as an error so the block keeps the
	 * customer on the checkout page (the cart is intact in Direct Card mode).
	 */
	function cancelPayment() {
		if ( flow.cancelling ) {
			return;
		}
		flow.cancelling = true;
		flow.cancelled = true; // stops pollStatus()

		var closeBtn = document.querySelector( '#rm-3ds-overlay .rm-3ds-close' );
		if ( closeBtn ) {
			closeBtn.disabled = true;
		}
		var titleEl = document.querySelector( '#rm-3ds-overlay .rm-3ds-title' );
		if ( titleEl ) {
			titleEl.textContent = t( 'cancelled' );
		}

		var handle = flow.handle || {};
		var done = function () {
			close3ds();
			if ( typeof flow.reject === 'function' ) {
				flow.reject( { cancelled: true } );
			}
		};

		if ( ! handle.oid || ! handle.key ) {
			done();
			return;
		}

		fetch( data.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			credentials: 'same-origin',
			body: encodeForm( {
				action: 'rm_direct_card_status',
				op: 'cancel',
				nonce: data.nonce,
				oid: handle.oid,
				key: handle.key
			} )
		} ).then( done, done );
	}

	/* ------------------------------------------------------------------ *
	 * Card flow orchestrator
	 * ------------------------------------------------------------------ */

	function runCardFlow( handle, card ) {
		flow = { cancelled: false, cancelling: false, handle: handle };

		return new Promise( function ( resolve, reject ) {
			flow.reject = reject;

			submitCardToRm( handle, card )
				.then( function ( item ) {
					if ( flow.cancelled ) {
						return;
					}
					if ( item.action === '3DS_CHALLENGE' && item.url ) {
						open3ds( item.url );
					} else {
						show3dsShell();
					}
					return pollStatus( handle ).then( function ( result ) {
						if ( flow.cancelled ) {
							return;
						}
						close3ds();
						if ( result.status === 'success' ) {
							resolve( result.redirect || handle.returnUrl );
						} else {
							reject( { message: t( 'generic' ), redirect: result.redirect || handle.failUrl } );
						}
					} );
				} )
				.catch( function ( err ) {
					if ( flow.cancelled ) {
						return;
					}
					close3ds();
					reject( { message: ( err && err.message ) || t( 'generic' ) } );
				} );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Block payment method content
	 * ------------------------------------------------------------------ */

	function CardRow( id, label, extraAttrs ) {
		var attrs = { id: id, className: 'input-text' };
		for ( var k in extraAttrs ) {
			if ( Object.prototype.hasOwnProperty.call( extraAttrs, k ) ) {
				attrs[ k ] = extraAttrs[ k ];
			}
		}
		return el(
			'p',
			{ key: id, className: 'form-row form-row-wide rm-dc-row' },
			el(
				'label',
				{ htmlFor: id },
				label,
				' ',
				el( 'span', { className: 'required' }, '*' )
			),
			el( 'input', attrs )
		);
	}

	function Content( props ) {
		var events = props.eventRegistration || {};
		var emitResponse = props.emitResponse || {};
		var responseTypes = emitResponse.responseTypes || { SUCCESS: 'success', ERROR: 'error' };
		var noticeContexts = emitResponse.noticeContexts || {};

		var payModeState = useState( 'card' );
		var payMode = payModeState[ 0 ];
		var setPayMode = payModeState[ 1 ];

		var noticeState = useState( '' );
		var notice = noticeState[ 0 ];
		var setNotice = noticeState[ 1 ];

		var refs = {
			name: useRef( null ),
			number: useRef( null ),
			expiry: useRef( null ),
			cvv: useRef( null )
		};

		// Surface a notice once, after a cancelled / failed payment.
		useEffect( function () {
			try {
				var stored = window.sessionStorage.getItem( 'rmDirectCardBlkNotice' );
				if ( stored ) {
					window.sessionStorage.removeItem( 'rmDirectCardBlkNotice' );
					setNotice( stored );
				}
			} catch ( e ) {
				/* ignore */
			}
		}, [] );

		// onPaymentSetup: hand rm_pay_mode to the Store API; validate the card.
		useEffect( function () {
			if ( typeof events.onPaymentSetup !== 'function' ) {
				return;
			}
			var unsubscribe = events.onPaymentSetup( function () {
				if ( ! DIRECT || payMode !== 'card' ) {
					return {
						type: responseTypes.SUCCESS,
						meta: { paymentMethodData: { rm_pay_mode: 'hosted' } }
					};
				}
				var card = readCard( refs );
				var check = validateCard( card );
				if ( ! check.ok ) {
					setNotice( check.message );
					return {
						type: responseTypes.ERROR,
						message: check.message,
						messageContext: noticeContexts.PAYMENTS
					};
				}
				setNotice( '' );
				return {
					type: responseTypes.SUCCESS,
					meta: { paymentMethodData: { rm_pay_mode: 'card' } }
				};
			} );
			return unsubscribe;
		}, [ events.onPaymentSetup, payMode ] );

		// onCheckoutSuccess: run the card flow when a handle came back.
		useEffect( function () {
			if ( typeof events.onCheckoutSuccess !== 'function' ) {
				return;
			}
			var unsubscribe = events.onCheckoutSuccess( function ( args ) {
				var pd = ( args && args.processingResponse && args.processingResponse.paymentDetails ) || {};
				if ( ! pd.rm_card_endpoint ) {
					return; // Hosted choice / fallback: let the block use the server redirect.
				}

				var handle = {
					endpoint: pd.rm_card_endpoint,
					code: pd.rm_card_code,
					oid: pd.rm_card_oid,
					key: pd.rm_card_key,
					returnUrl: pd.rm_card_return_url,
					failUrl: pd.rm_card_fail_url
				};

				var card = readCard( refs );
				clearCard( refs );

				return runCardFlow( handle, card ).then(
					function ( dest ) {
						// Success: drive navigation ourselves and leave the block
						// waiting (it must not also redirect).
						navigate( dest || handle.returnUrl );
						return new Promise( function () {} );
					},
					function ( err ) {
						err = err || {};
						if ( err.cancelled ) {
							try {
								window.sessionStorage.setItem( 'rmDirectCardBlkNotice', t( 'cancelled' ) );
							} catch ( e ) { /* ignore */ }
						}
						return {
							type: responseTypes.ERROR,
							message: err.message || t( 'cancelled' ),
							messageContext: noticeContexts.PAYMENTS
						};
					}
				);
			} );
			return unsubscribe;
		}, [ events.onCheckoutSuccess ] );

		if ( ! DIRECT ) {
			return description ? el( 'p', null, description ) : null;
		}

		var children = [];
		if ( description ) {
			children.push( el( 'p', { key: 'desc' }, description ) );
		}

		children.push(
			el(
				'p',
				{ key: 'mode-card', className: 'rm-pay-mode' },
				el(
					'label',
					null,
					el( 'input', {
						type: 'radio',
						checked: payMode === 'card',
						onChange: function () {
							setNotice( '' );
							setPayMode( 'card' );
						}
					} ),
					el( 'span', null, t( 'card', 'Card' ) )
				)
			)
		);

		if ( payMode === 'card' ) {
			children.push(
				el( 'div', { key: 'fields', className: 'rm-direct-card' }, [
					CardRow( 'rm-blk-card-name', t( 'cardName', 'Cardholder Name' ), {
						key: 'r-name',
						ref: refs.name,
						type: 'text',
						autoComplete: 'cc-name'
					} ),
					CardRow( 'rm-blk-card-number', t( 'cardNumber', 'Card Number' ), {
						key: 'r-number',
						ref: refs.number,
						type: 'text',
						inputMode: 'numeric',
						autoComplete: 'cc-number',
						maxLength: 23,
						placeholder: '1234 5678 9012 3456',
						onInput: formatNumber
					} ),
					CardRow( 'rm-blk-card-expiry', t( 'cardExpiry', 'Expiry (MM / YY)' ), {
						key: 'r-expiry',
						ref: refs.expiry,
						type: 'text',
						inputMode: 'numeric',
						autoComplete: 'cc-exp',
						maxLength: 7,
						placeholder: 'MM / YY',
						onInput: formatExpiry
					} ),
					CardRow( 'rm-blk-card-cvv', t( 'cardCvv', 'Security Code / CVV' ), {
						key: 'r-cvv',
						ref: refs.cvv,
						type: 'text',
						inputMode: 'numeric',
						autoComplete: 'cc-csc',
						maxLength: 4,
						placeholder: 'CVV'
					} ),
					notice
						? el(
							'div',
							{ key: 'notice', className: 'rm-direct-card__notice' },
							notice
						)
						: null
				] )
			);
		}

		children.push(
			el(
				'p',
				{ key: 'mode-hosted', className: 'rm-pay-mode' },
				el(
					'label',
					null,
					el( 'input', {
						type: 'radio',
						checked: payMode === 'hosted',
						onChange: function () {
							setNotice( '' );
							setPayMode( 'hosted' );
						}
					} ),
					el( 'span', null, t( 'ewallet', 'E-wallet / Online Banking' ) )
				)
			)
		);

		if ( payMode === 'hosted' ) {
			children.push(
				el(
					'p',
					{ key: 'hint', className: 'rm-pay-mode__hint' },
					t( 'redirectHint', 'You will be redirected to Revenue Monster to complete payment.' )
				)
			);
		}

		return el( 'div', { className: 'rm-pay-modes' }, children );
	}

	function Edit() {
		return description ? el( 'p', null, description ) : null;
	}

	registry.registerPaymentMethod( {
		name: 'revenuemonster',
		label: title,
		ariaLabel: title,
		canMakePayment: function () { return true; },
		content: el( Content, null ),
		edit: el( Edit, null ),
		supports: {
			features: Array.isArray( data.supports ) ? data.supports : [ 'products' ]
		}
	} );
} )();
