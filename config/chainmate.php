<?php

/*
|--------------------------------------------------------------------------
| ChainMate branding
|--------------------------------------------------------------------------
|
| Fork-specific settings for the ChainMate build of Snipe-IT. Keep all
| ChainMate-only configuration here so upstream merges stay simple.
|
*/

return [

    /*
    | Public URL of this fork's source code. Snipe-IT is AGPLv3, so users who
    | interact with a modified copy over a network must be offered its source.
    | The link is shown in the web UI footer.
    */
    'source_url' => env('CHAINMATE_SOURCE_URL', ''),

    /*
    | Replacements for the upstream "User Manual" and "Report a Bug" footer
    | links. Leave empty to hide the link.
    */
    'docs_url' => env('CHAINMATE_DOCS_URL', ''),

    'support_url' => env('CHAINMATE_SUPPORT_URL', ''),

];
