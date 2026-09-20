<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\Location;
use App\Models\Maintenance;
use App\Models\MaintenanceType;
use App\Models\Manufacturer;
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * ChainMate lifting-equipment demo data.
 *
 * Unlike Snipe-IT's stock seeders this one is ADDITIVE and idempotent - it
 * never truncates, so it is safe to run against an install that already has a
 * real admin user. Everything is matched by name / asset tag and re-used on a
 * second run.
 *
 * The data mirrors the ChainMate mobile app's demo set (ChainMate-App-Design,
 * src/data.ts): the same sites, asset tags, gear types and quarterly
 * inspection rhythm, so the admin panel and the app tell the same story.
 *
 *     php artisan db:seed --class=ChainMateDemoSeeder
 */
class ChainMateDemoSeeder extends Seeder
{
    /**
     * Inspection interval in days. AS 3775.2 ties frequency to duty cycle;
     * 6-25 lifts a week lands on a quarterly inspection.
     */
    private const INSPECTION_INTERVAL_DAYS = 92;

    /**
     * Days until the next inspection is due, per status. Negative is overdue.
     *
     * @var array<string, int>
     */
    private const DUE_IN_DAYS = [
        'In date' => 64,
        'Due soon' => 8,
        'Overdue' => -11,
        'Quarantined' => 26,
    ];

    private User $admin;

    /** @var array<string, Statuslabel> */
    private array $statuses = [];

    /** @var array<string, AssetModel> */
    private array $models = [];

    /** @var array<string, Location> */
    private array $sites = [];

    /** @var array<int, User> */
    private array $crew = [];

    /** @var array<string, CustomField> */
    private array $fields = [];

    /** @var array<string, array<string, string|null>> */
    private array $specs = [];

    private CustomFieldset $fieldset;

    public function run(): void
    {
        $admin = User::where('permissions->superuser', '1')->orderBy('id')->first();

        if (! $admin) {
            $this->command->error('No superuser found. Complete the setup wizard first.');

            return;
        }

        $this->admin = $admin;

        $this->seedStatusLabels();
        $this->seedCustomFields();
        $this->seedSites();
        $this->seedCrew();
        $this->seedModels();
        $this->seedAssets();
        $this->seedMaintenance();
        $this->seedSettings();

        $this->command->info('ChainMate demo data seeded.');
    }

    /**
     * Status labels matching the app's verdicts.
     */
    private function seedStatusLabels(): void
    {
        $labels = [
            ['In date', 'Inspected and safe to use.', ['deployable' => 1], '#16A34A'],
            ['Due soon', 'Inspection due within 14 days - still usable.', ['deployable' => 1], '#F97316'],
            ['Overdue', 'Inspection overdue. Locked from check-out until re-inspected.', [], '#DC2626'],
            ['Quarantined', 'Withdrawn from service pending repair, retest or destruction.', [], '#1F2937'],
        ];

        foreach ($labels as [$name, $notes, $flags, $color]) {
            $label = Statuslabel::firstOrNew(['name' => $name]);
            $label->fill(array_merge([
                'notes' => $notes,
                'deployable' => 0,
                'pending' => 0,
                'archived' => 0,
            ], $flags));
            $label->color = $color;
            $label->created_by = $this->admin->id;
            $label->save();

            $this->statuses[$name] = $label;
        }
    }

    /**
     * Lifting-gear specs as custom fields, gathered into one fieldset.
     *
     * Inspection dates are deliberately NOT custom fields: Snipe-IT's native
     * audit (last_audit_date / next_audit_date) already models them and drives
     * the "Due for Audit" report and the upcoming-audit notifications.
     */
    private function seedCustomFields(): void
    {
        $definitions = [
            ['Working Load Limit (t)', 'text', 'NUMERIC', null, 'Rated capacity in tonnes at the in-service configuration.'],
            ['Grade', 'listbox', 'ANY', "Grade 80\nGrade 100 (V)\nGrade S (6)\nGrade 8\nPolyester round\nPolyester webbing\n6x36 IWRC\nAlloy steel\nBrake winch", 'Material or chain grade.'],
            ['Configuration', 'listbox', 'ANY', "Single leg\n2-leg\n3-leg\n4-leg\nEndless\nN/A", 'Number of legs, for slings.'],
            ['Size', 'text', 'ANY', null, 'Chain, rope, pin or flange size.'],
            ['Reach', 'text', 'ANY', null, 'Working length or reach.'],
            ['Standard', 'listbox', 'ANY', "AS 3775.2\nAS 2741\nAS 4497\nAS 1353\nAS 1418.2\nAS 1666.1\nAS 3776\nAS 4991\nAS 2317", 'Standard the item is inspected against.'],
            ['Certificate No.', 'text', 'ANY', null, 'Proof-test or retest certificate reference.'],
            ['Duty Cycle', 'listbox', 'ANY', "Up to 5 lifts/week\n6-25 lifts/week\n26-100 lifts/week\nOver 100 lifts/week", 'Drives how often the item must be inspected.'],
        ];

        foreach ($definitions as [$name, $element, $format, $values, $help]) {
            $field = CustomField::firstOrNew(['name' => $name]);

            if (! $field->exists) {
                $field->fill([
                    'element' => $element,
                    'format' => $format,
                    'field_values' => $values,
                    'help_text' => $help,
                    'show_in_listview' => in_array($name, ['Working Load Limit (t)', 'Grade'], true),
                    // Surfaced on the audit form so an inspector can confirm
                    // the certificate reference while the item is in hand.
                    'display_audit' => $name === 'Certificate No.',
                ]);
                $field->created_by = $this->admin->id;
                $field->save();
            }

            $this->fields[$name] = $field->fresh();
        }

        $fieldset = CustomFieldset::firstOrCreate(
            ['name' => 'Lifting Equipment'],
            ['created_by' => $this->admin->id],
        );

        $order = 0;
        foreach ($this->fields as $field) {
            $exists = DB::table('custom_field_custom_fieldset')
                ->where('custom_field_id', $field->id)
                ->where('custom_fieldset_id', $fieldset->id)
                ->exists();

            if (! $exists) {
                DB::table('custom_field_custom_fieldset')->insert([
                    'custom_field_id' => $field->id,
                    'custom_fieldset_id' => $fieldset->id,
                    'order' => $order,
                    'required' => 0,
                ]);
            }

            $order++;
        }

        $this->fieldset = $fieldset;
    }

    /**
     * Sites, as locations.
     */
    private function seedSites(): void
    {
        $sites = [
            ['Wynyard Point - Stage 2', '32 Beaumont St, Wynyard Quarter', 'Auckland', '1010'],
            ['City Rail Link - Karangahape', 'Mercury Lane, Newton', 'Auckland', '1010'],
            ['Onehunga Depot & Test Facility', '15 Galway St, Onehunga', 'Auckland', '1061'],
            ['Drury South Interchange', 'Great South Rd, Drury', 'Auckland', '2113'],
            ['Te Ngakau Civic Precinct', '101 Wakefield St, Te Aro', 'Wellington', '6011'],
            ['Lyttelton Port - Cashin Quay', 'Cashin Quay, Lyttelton', 'Christchurch', '8082'],
        ];

        foreach ($sites as [$name, $address, $city, $zip]) {
            $location = Location::firstOrCreate(['name' => $name], [
                'address' => $address,
                'city' => $city,
                'state' => '',
                'country' => 'NZ',
                'zip' => $zip,
                'currency' => 'NZD',
                'created_by' => $this->admin->id,
            ]);

            $this->sites[$name] = $location;
        }
    }

    /**
     * Riggers and inspectors who hold the gear.
     *
     * These are demo records, not usable accounts: each gets a random password
     * nobody holds, so they can be custodians without being a way in.
     */
    private function seedCrew(): void
    {
        $people = [
            ['Hemi', 'Walker', 'Senior Rigger', 'Wynyard Point - Stage 2'],
            ['Aroha', 'Ngata', 'Lifting Equipment Inspector', 'Onehunga Depot & Test Facility'],
            ['Tane', 'Fisher', 'Lifting Equipment Inspector', 'Onehunga Depot & Test Facility'],
            ['Sione', 'Latu', 'Dogman', 'City Rail Link - Karangahape'],
            ['Mere', 'Katene', 'Site Supervisor', 'Drury South Interchange'],
            ['Riki', 'Thompson', 'H&S Officer', 'Lyttelton Port - Cashin Quay'],
        ];

        foreach ($people as [$first, $last, $title, $site]) {
            $username = Str::lower($first.'.'.$last);

            $user = User::firstOrNew(['username' => $username]);

            if (! $user->exists) {
                $user->fill([
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $username.'@chainmate.test',
                    'jobtitle' => $title,
                    'activated' => 1,
                    'location_id' => $this->sites[$site]->id ?? null,
                    'created_by' => $this->admin->id,
                ]);
                $user->password = Hash::make(Str::random(40));
                $user->save();
            }

            $this->crew[] = $user;
        }
    }

    /**
     * One asset model per gear type, carrying the category, manufacturer and
     * the Lifting Equipment fieldset.
     */
    private function seedModels(): void
    {
        // prefix => [model name, category, manufacturer, grade, size, reach, standard, configuration]
        $types = [
            'CM' => ['Grade 100 Chain Sling 10mm', 'Chain Slings', 'Pewag', 'Grade 100 (V)', '10 mm', '3.5 m', 'AS 3775.2', '2-leg'],
            'SH' => ['Bow Shackle 25mm Pin', 'Shackles & Fittings', 'Crosby', 'Grade S (6)', '25 mm', null, 'AS 2741', 'N/A'],
            'RS' => ['Polyester Round Sling 60mm', 'Synthetic Slings', 'Spanset', 'Polyester round', '60 mm', '2.0 m', 'AS 4497', 'Endless'],
            'WS' => ['Polyester Webbing Sling 50mm', 'Synthetic Slings', 'Spanset', 'Polyester webbing', '50 mm', '3.0 m', 'AS 1353', 'Single leg'],
            'LH' => ['Lever Hoist 6mm Chain', 'Hoists & Winches', 'Yale', 'Grade 80', '6 mm', '3.0 m', 'AS 1418.2', 'N/A'],
            'CB' => ['Chain Block 8mm Chain', 'Hoists & Winches', 'Tiger Lifting', 'Grade 80', '8 mm', '6.0 m', 'AS 1418.2', 'N/A'],
            'WR' => ['Wire Rope Sling 16mm 6x36 IWRC', 'Wire Rope Slings', "Shaw's Wire Ropes", '6x36 IWRC', '16 mm', '4.0 m', 'AS 1666.1', 'Single leg'],
            'ML' => ['Master Link 16mm', 'Shackles & Fittings', 'Gunnebo', 'Grade 100', '16 mm', null, 'AS 3776', 'N/A'],
            'BC' => ['Beam Clamp 75-230mm Flange', 'Beam Clamps & Anchors', 'Tiger Lifting', 'Alloy steel', '75-230 mm', null, 'AS 4991', 'N/A'],
            'EB' => ['Eye Bolt M20', 'Beam Clamps & Anchors', 'Crosby', 'Grade 8', 'M20', null, 'AS 2317', 'N/A'],
            'HW' => ['Hand Winch 5mm Cable', 'Hoists & Winches', 'Tiger Lifting', 'Brake winch', '5 mm', '10 m', 'AS 1418.2', 'N/A'],
            'HP' => ['Hand Puller 6mm Cable', 'Hoists & Winches', 'Tiger Lifting', 'Alloy steel', '6 mm', '3.0 m', 'AS 1418.2', 'N/A'],
        ];

        foreach ($types as $prefix => [$name, $categoryName, $manufacturerName, $grade, $size, $reach, $standard, $configuration]) {
            $category = Category::firstOrCreate(
                ['name' => $categoryName, 'category_type' => 'asset'],
                [
                    'require_acceptance' => false,
                    'use_default_eula' => false,
                    'checkin_email' => false,
                    'created_by' => $this->admin->id,
                ],
            );

            $manufacturer = Manufacturer::firstOrCreate(
                ['name' => $manufacturerName],
                ['created_by' => $this->admin->id],
            );

            $model = AssetModel::firstOrCreate(['name' => $name], [
                'category_id' => $category->id,
                'manufacturer_id' => $manufacturer->id,
                'fieldset_id' => $this->fieldset->id,
                'model_number' => $prefix.'-'.Str::upper(Str::random(5)),
                'notes' => 'Seeded by ChainMateDemoSeeder',
                'created_by' => $this->admin->id,
            ]);

            $this->models[$prefix] = $model;
            $this->specs[$prefix] = compact('grade', 'size', 'reach', 'standard', 'configuration');
        }
    }

    /**
     * The gear itself, tag for tag with the app's demo data.
     */
    private function seedAssets(): void
    {
        // [tag, name, WLL, status, site]
        $gear = [
            ['CM-4471', '2-Leg Chain Sling 5.3t', '5.3', 'Overdue', 'Wynyard Point - Stage 2'],
            ['CM-4472', '2-Leg Chain Sling 4.2t', '4.2', 'Overdue', 'Wynyard Point - Stage 2'],
            ['SH-2210', 'Bow Shackle 8.5t', '8.5', 'Overdue', 'Wynyard Point - Stage 2'],
            ['CM-4480', '4-Leg Chain Sling 11.2t', '11.2', 'Quarantined', 'Wynyard Point - Stage 2'],
            ['RS-1180', 'Round Sling 3t', '3', 'In date', 'Wynyard Point - Stage 2'],
            ['WS-0442', 'Webbing Sling 2t', '2', 'Due soon', 'Wynyard Point - Stage 2'],
            ['LH-3301', 'Lever Hoist 1.5t', '1.5', 'In date', 'Wynyard Point - Stage 2'],
            ['CB-7712', 'Chain Block 3t', '3', 'In date', 'Wynyard Point - Stage 2'],
            ['ML-0091', 'Master Link 15t', '15', 'In date', 'Wynyard Point - Stage 2'],
            ['BC-2204', 'Beam Clamp 5t', '5', 'Due soon', 'Wynyard Point - Stage 2'],
            ['WR-5510', 'Wire Rope Sling 6t', '6', 'In date', 'Wynyard Point - Stage 2'],
            ['EB-1120', 'Eye Bolt 2t', '2', 'In date', 'Wynyard Point - Stage 2'],
            ['HW-6601', 'Hand Winch 0.9t', '0.9', 'In date', 'Wynyard Point - Stage 2'],
            ['HP-8801', 'Hand Puller 2t', '2', 'Due soon', 'Wynyard Point - Stage 2'],
            ['CM-4490', '2-Leg Chain Sling 5.3t', '5.3', 'In date', 'City Rail Link - Karangahape'],
            ['WS-0455', 'Webbing Sling 4t', '4', 'In date', 'City Rail Link - Karangahape'],
            ['CB-7720', 'Chain Block 5t', '5', 'In date', 'City Rail Link - Karangahape'],
            ['SH-2230', 'Bow Shackle 12t', '12', 'In date', 'City Rail Link - Karangahape'],
            ['CM-4500', '2-Leg Chain Sling 5.3t', '5.3', 'In date', 'Onehunga Depot & Test Facility'],
            ['CM-4501', '2-Leg Chain Sling 5.3t', '5.3', 'In date', 'Onehunga Depot & Test Facility'],
            ['RS-1190', 'Round Sling 3t', '3', 'In date', 'Onehunga Depot & Test Facility'],
            ['LH-3310', 'Lever Hoist 3t', '3', 'In date', 'Onehunga Depot & Test Facility'],
            ['WR-5520', 'Wire Rope Sling 6t', '6', 'In date', 'Onehunga Depot & Test Facility'],
            ['ML-0100', 'Master Link 15t', '15', 'In date', 'Onehunga Depot & Test Facility'],
            ['CM-4510', '4-Leg Chain Sling 11.2t', '11.2', 'Overdue', 'Drury South Interchange'],
            ['WS-0460', 'Webbing Sling 2t', '2', 'In date', 'Drury South Interchange'],
            ['BC-2210', 'Beam Clamp 5t', '5', 'In date', 'Drury South Interchange'],
            ['CM-4520', '2-Leg Chain Sling 4.2t', '4.2', 'In date', 'Te Ngakau Civic Precinct'],
            ['RS-1200', 'Round Sling 3t', '3', 'In date', 'Te Ngakau Civic Precinct'],
            ['CM-4530', '4-Leg Chain Sling 11.2t', '11.2', 'Overdue', 'Lyttelton Port - Cashin Quay'],
            ['CM-4531', '2-Leg Chain Sling 5.3t', '5.3', 'Overdue', 'Lyttelton Port - Cashin Quay'],
            ['WR-5530', 'Wire Rope Sling 8t', '8', 'In date', 'Lyttelton Port - Cashin Quay'],
            ['CB-7730', 'Chain Block 3t', '3', 'In date', 'Lyttelton Port - Cashin Quay'],
            ['SH-2240', 'Bow Shackle 8.5t', '8.5', 'Due soon', 'Lyttelton Port - Cashin Quay'],
        ];

        foreach ($gear as $index => [$tag, $name, $wll, $statusName, $siteName]) {
            if (Asset::withTrashed()->where('asset_tag', $tag)->exists()) {
                continue;
            }

            $prefix = Str::before($tag, '-');
            $model = $this->models[$prefix];
            $site = $this->sites[$siteName];
            $status = $this->statuses[$statusName];

            $dueInDays = self::DUE_IN_DAYS[$statusName];
            $nextDue = Carbon::now()->addDays($dueInDays)->startOfDay();
            $lastAudit = (clone $nextDue)->subDays(self::INSPECTION_INTERVAL_DAYS);

            $asset = new Asset;
            $asset->fill([
                'asset_tag' => $tag,
                'name' => $name,
                'model_id' => $model->id,
                'status_id' => $status->id,
                'rtd_location_id' => $site->id,
                'location_id' => $site->id,
                'serial' => Str::upper($prefix.Str::random(9)),
                'purchase_date' => Carbon::now()->subMonths(6 + ($index % 30))->toDateString(),
                'purchase_cost' => $this->priceFor($prefix, (float) $wll),
                'last_audit_date' => $lastAudit,
                'next_audit_date' => $nextDue->toDateString(),
                'notes' => $statusName === 'Quarantined'
                    ? 'Withdrawn from service - failed quarterly inspection, awaiting repair and retest.'
                    : null,
            ]);
            $asset->created_by = $this->admin->id;

            $specs = $this->specs[$prefix];
            $this->setCustomValue($asset, 'Working Load Limit (t)', $wll);
            $this->setCustomValue($asset, 'Grade', $specs['grade']);
            $this->setCustomValue($asset, 'Configuration', $this->configurationFor($name, $specs['configuration']));
            $this->setCustomValue($asset, 'Size', $specs['size']);
            $this->setCustomValue($asset, 'Reach', $specs['reach']);
            $this->setCustomValue($asset, 'Standard', $specs['standard']);
            $this->setCustomValue($asset, 'Certificate No.', 'STC-'.Str::upper(Str::replace('-', '', $tag)));
            $this->setCustomValue($asset, 'Duty Cycle', '6-25 lifts/week');

            // Gear in service is held by a rigger. Quarantined and overdue
            // items sit at the site instead - the app locks them out of
            // check-out until they have been re-inspected.
            if (in_array($statusName, ['In date', 'Due soon'], true) && $index % 3 === 0) {
                $custodian = $this->crew[$index % count($this->crew)];
                $asset->assigned_to = $custodian->id;
                $asset->assigned_type = User::class;
                $asset->last_checkout = Carbon::now()->subDays(3 + ($index % 20));
            }

            $asset->save();
        }
    }

    /**
     * Repairs, proof tests and retests against the gear that needs them.
     */
    private function seedMaintenance(): void
    {
        $types = [
            'Proof Test' => '#2563EB',
            'Retest' => '#16A34A',
            'Repair' => '#F97316',
            'Thorough Examination' => '#1F2937',
        ];

        foreach ($types as $name => $color) {
            MaintenanceType::firstOrCreate(['name' => $name], [
                'tag_color' => $color,
                'created_by' => $this->admin->id,
            ]);
        }

        $repair = MaintenanceType::where('name', 'Repair')->first();
        $retest = MaintenanceType::where('name', 'Retest')->first();

        $jobs = [
            ['CM-4480', $repair, 'Damaged master link - replace and proof test before return to service.', null],
            ['CM-4510', $retest, 'Overdue quarterly inspection - booked in for thorough examination.', null],
            ['SH-2210', $retest, 'Pin wear check after overload event.', Carbon::now()->subDays(9)],
        ];

        foreach ($jobs as [$tag, $type, $notes, $completedAt]) {
            $asset = Asset::where('asset_tag', $tag)->first();

            if (! $asset || ! $type) {
                continue;
            }

            $exists = Maintenance::where('item_id', $asset->id)
                ->where('item_type', Asset::class)
                ->where('maintenance_type_id', $type->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $maintenance = new Maintenance;
            $maintenance->forceFill([
                'item_id' => $asset->id,
                'item_type' => Asset::class,
                'maintenance_type_id' => $type->id,
                'name' => $type->name.' - '.$asset->asset_tag,
                'start_date' => Carbon::now()->subDays(12)->toDateString(),
                'expected_completion_date' => Carbon::now()->addDays(5)->toDateString(),
                'completed_at' => $completedAt,
                'is_warranty' => 0,
                'notes' => $notes,
                'cost' => 180.00,
                'created_by' => $this->admin->id,
            ]);
            $maintenance->save();
        }
    }

    /**
     * Quarterly inspections, warned about a month out.
     */
    private function seedSettings(): void
    {
        $settings = Setting::getSettings();

        if (! $settings) {
            return;
        }

        $settings->audit_interval = 3;
        $settings->audit_warning_days = 30;
        $settings->save();
    }

    private function setCustomValue(Asset $asset, string $fieldName, ?string $value): void
    {
        if ($value === null || ! isset($this->fields[$fieldName])) {
            return;
        }

        $asset->{$this->fields[$fieldName]->db_column} = $value;
    }

    /**
     * Slings carry their leg count in the name ("4-Leg Chain Sling 11.2t");
     * everything else falls back to the type's default.
     */
    private function configurationFor(string $name, ?string $default): ?string
    {
        foreach (['2-Leg' => '2-leg', '3-Leg' => '3-leg', '4-Leg' => '4-leg'] as $needle => $configuration) {
            if (Str::contains($name, $needle)) {
                return $configuration;
            }
        }

        return $default;
    }

    private function priceFor(string $prefix, float $wll): float
    {
        $base = match ($prefix) {
            'CM' => 340,
            'WR' => 260,
            'RS', 'WS' => 95,
            'LH', 'CB' => 520,
            'HW', 'HP' => 300,
            'BC' => 410,
            default => 70,
        };

        return round($base + ($wll * 28), 2);
    }
}
