<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ApplyChainMateBranding;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplyChainMateBrandingTest extends TestCase
{
    public function test_applies_brand_settings_and_logos()
    {
        Storage::fake('public');

        $this->artisan('chainmate:branding')->assertExitCode(0);

        $settings = Setting::first();

        $this->assertEquals('ChainMate', $settings->site_name);
        $this->assertEquals(2, $settings->brand);
        $this->assertEquals('off', $settings->support_footer);
        $this->assertEquals('en-NZ', $settings->locale);

        foreach (ApplyChainMateBranding::COLORS as $column => $color) {
            $this->assertEquals($color, $settings->{$column});
        }

        foreach (array_keys(ApplyChainMateBranding::LOGOS) as $column) {
            Storage::disk('public')->assertExists($settings->{$column});
        }
    }

    public function test_keep_logos_option_leaves_uploaded_logos_alone()
    {
        Storage::fake('public');
        $settings = Setting::first();
        $settings->logo = 'our-own-logo.png';
        $settings->save();

        $this->artisan('chainmate:branding', ['--keep-logos' => true, '--site-name' => 'ChainMate NZ'])->assertExitCode(0);

        $settings = Setting::first();

        $this->assertEquals('our-own-logo.png', $settings->logo);
        $this->assertEquals('ChainMate NZ', $settings->site_name);
        Storage::disk('public')->assertMissing('chainmate-logo.svg');
    }
}
