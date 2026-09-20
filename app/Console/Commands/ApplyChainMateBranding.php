<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ApplyChainMateBranding extends Command
{
    /**
     * @var string
     */
    protected $signature = 'chainmate:branding
                            {--site-name=ChainMate : The site name shown in the header, page titles and emails}
                            {--keep-logos : Do not overwrite uploaded logos and favicon}';

    /**
     * @var string
     */
    protected $description = 'Apply the ChainMate brand (name, colours, logos, footer and locale) to the branding settings.';

    /**
     * Brand colours, matching the ChainMate mobile app.
     *
     * @var array<string, string>
     */
    public const COLORS = [
        'header_color' => '#FF5F00',
        'nav_link_color' => '#FFFFFF',
        'link_light_color' => '#C2410C',
        'link_dark_color' => '#FB923C',
    ];

    /**
     * Setting column => file in public/img that gets copied to the public disk.
     *
     * @var array<string, string>
     */
    public const LOGOS = [
        'logo' => 'chainmate-logo.svg',
        'email_logo' => 'chainmate-icon.png',
        'label_logo' => 'chainmate-icon.png',
        'acceptance_pdf_logo' => 'chainmate-icon.png',
        'favicon' => 'chainmate-icon.png',
    ];

    public function handle(): int
    {
        $settings = Setting::getSettings();

        if (! $settings) {
            $this->error('No settings found. Run the setup wizard first.');

            return self::FAILURE;
        }

        $settings->site_name = $this->option('site-name');
        $settings->brand = 2;
        $settings->support_footer = 'off';
        $settings->version_footer = 'admin';
        $settings->locale = 'en-NZ';

        foreach (self::COLORS as $column => $color) {
            $settings->{$column} = $color;
        }

        if (! $this->option('keep-logos')) {
            foreach (self::LOGOS as $column => $file) {
                $storedName = 'chainmate-'.$column.'.'.pathinfo($file, PATHINFO_EXTENSION);
                Storage::disk('public')->put($storedName, file_get_contents(public_path('img/'.$file)));
                $settings->{$column} = $storedName;
            }
        }

        $settings->save();

        $this->info('ChainMate branding applied.');

        if (config('chainmate.source_url') == '') {
            $this->warn('CHAINMATE_SOURCE_URL is not set. Snipe-IT is AGPLv3: link your fork\'s public source in the footer.');
        }

        return self::SUCCESS;
    }
}
