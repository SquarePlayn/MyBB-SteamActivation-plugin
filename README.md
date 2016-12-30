# MyBB-SteamActivation-plugin
This MyBB plugin verifies/activates new forum users via Steam login.

<b>How to install:</b> <ul>
<li>Put the PHP file in your /inc/plugins/ folder</li>
<li>Install and activate the plugin in your AdminCP</li>
<li>Also make sure to adjust the settings.</li><ul>

You NEED to provide the plugin with a bit of info in the first few settings, so do not skip this or you might get unexpected results

<b>NOTE</b> This plugin needs a specific OpenID library to connect to the SteamAPI. Download it <a href="https://github.com/opauth/openid/blob/master/Vendor/lightopenid/openid.php">here</a> and put it in the /inc/plugins folder (the same folder that this plugin should go in).
Full link to the OpenID file: https://github.com/opauth/openid/blob/master/Vendor/lightopenid/openid.php
