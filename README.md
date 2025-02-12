# Installation

1. Download and install wordpress
2. Install WooCommerce
3. Navigate to `wp-content/plugins` folder
```bash
cd wp-content/plugins
```
4. Git clone this repository to the folder
```bash
git clone https://github.com/RevenueMonster/woocommerce-revenuemonster-payment-gateway.git
```
5. Hooray, you have completed the steps


### Possible issues

If configuration page is loaded but payment method is not showing up in customer checkout page, this is due to a conflict in WooCommerce UI blocks. 

Steps to resolve:

1. Install the [Classic editor plugin](https://wordpress.org/plugins/classic-editor/)
2. Edit the checkout page
3. Switch to text mode
4. Replace all content with this text `[woocommerce_checkout]`
5. Clear cache and republish.