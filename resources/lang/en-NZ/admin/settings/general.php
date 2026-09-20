<?php

/*
| ChainMate overrides. Only the keys listed here differ from en-US; every
| other key falls back to resources/lang/en-US one key at a time.
*/

return [
    'color_settings_help' => 'These settings will be used throughout ChainMate.  Users are able to override the link colors by editing their account preferences to meet their individual readability requirements.',
    'link_dark_color_help' => 'Select a color that will provide enough contrast for people that use ChainMate in dark mode.',
    'link_light_color_help' => 'Select a color that will provide enough contrast for people that use ChainMate in light mode.',
    'ldap_display_name_help' => 'If you have a separate displayName field in your LDAP/AD, map it here and it will be used for displaying users within ChainMate.',
    'ldap_activated_flag_help' => 'This value is used to determine whether a synced user can login to ChainMate. <strong>It does not affect the ability to check items in or out to them</strong>, and should be the <strong>attribute name</strong> within your AD/LDAP, <strong>not the value</strong>. <br><br>If this field is set to a field name that does not exist in your AD/LDAP, or the value in the AD/LDAP field is set to <code>0</code> or <code>false</code>, <strong>user login will be disabled</strong>. If the value in the AD/LDAP field is set to <code>1</code> or <code>true</code> or <em>any other text</em> means the user can log in. When the field is blank in your AD, we respect the <code>userAccountControl</code> attribute, which usually allows non-suspended users to log in.',
    'load_remote_help_text' => 'Uncheck this box if your install cannot load scripts from the outside internet. This will prevent ChainMate from trying load avatars from Gravatar or other outside sources.',
    'login_remote_user_custom_logout_url_help' => 'If a url is provided here, users will get redirected to this URL after the user logs out of ChainMate. This is useful to close the user sessions of your Authentication provider correctly.',
    'show_images_in_email_help' => 'Uncheck this box if your ChainMate installation is behind a VPN or closed network and users outside the network will not be able to load images served from this installation in their emails.',
    'snipe_version' => 'ChainMate version',
    'support_footer_help' => 'Specify who sees the links to the ChainMate Support info and Users Manual',
    'version_footer_help' => 'Specify who sees the ChainMate version and build number.',
    'show_url_in_emails' => 'Link to ChainMate in Emails',
    'show_url_in_emails_help_text' => 'Uncheck this box if you do not wish to link back to your ChainMate installation in your email footers. Useful if most of your users never login. ',
    'barcodes_help' => 'This will attempt to delete cached barcodes. This would typically only be used if your barcode settings have changed, or if your ChainMate URL has changed. Barcodes will be re-generated when accessed next.',
    'label2_2d_target_help' => 'The data that will be contained in the 2D barcode. This can link to the asset directly in ChainMate or can be one of the non-linked field values. If you use the prefix above, it will be prepended to this value.',
];
