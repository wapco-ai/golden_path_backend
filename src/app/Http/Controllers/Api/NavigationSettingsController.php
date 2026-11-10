<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NavigationSettingsController extends Controller
{
    private function payload(object $row): array
    {
        return ['policy' => json_decode($row->navigation_policy, true, 512, JSON_THROW_ON_ERROR),
            'version' => (int) $row->navigation_version, 'walk_speed_mps' => (float) $row->walk_speed_mps];
    }

    public function show()
    {
        $row = DB::table('routing_timing_settings')->where('singleton', true)->first();
        abort_unless($row, 503, 'Navigation settings unavailable');
        return response()->json($this->payload($row))->header('Cache-Control', 'no-store');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'walk_speed_mps' => ['required', 'numeric', 'between:0.2,3'],
            'policy' => ['required', 'array:gps_enabled,warning_uncertainty_m,snap_distance_m,arrival_radius_m,qr_accuracy_m,manual_accuracy_m,step_length_m,drift_per_meter,heading_max_age_ms,measurement_max_age_ms,route_window_m,heading_tolerance_deg,arrival_samples,arrival_dwell_ms,gps_max_accuracy_m,gps_progress_accuracy_m,gps_arrival_accuracy_m'],
            'policy.gps_enabled' => ['required', 'boolean'],
            'policy.warning_uncertainty_m' => ['required', 'numeric', 'between:5,100'],
            'policy.snap_distance_m' => ['required', 'numeric', 'between:1,100'],
            'policy.arrival_radius_m' => ['required', 'numeric', 'between:1,5'],
            'policy.qr_accuracy_m' => ['required', 'numeric', 'between:0.5,5'],
            'policy.manual_accuracy_m' => ['required', 'numeric', 'between:1,10'],
            'policy.step_length_m' => ['required', 'numeric', 'between:0.35,1.1'],
            'policy.drift_per_meter' => ['required', 'numeric', 'between:0.05,1'],
            'policy.heading_max_age_ms' => ['required', 'numeric', 'between:500,10000'],
            'policy.measurement_max_age_ms' => ['required', 'numeric', 'between:1000,30000'],
            'policy.route_window_m' => ['required', 'numeric', 'between:1,6'],
            'policy.heading_tolerance_deg' => ['required', 'numeric', 'between:15,90'],
            'policy.arrival_samples' => ['required', 'integer', 'between:2,5'],
            'policy.arrival_dwell_ms' => ['required', 'numeric', 'between:300,3000'],
            'policy.gps_max_accuracy_m' => ['required', 'numeric', 'between:3,50'],
            'policy.gps_progress_accuracy_m' => ['required', 'numeric', 'between:1,10'],
            'policy.gps_arrival_accuracy_m' => ['required', 'numeric', 'between:1,5'],
        ]);
        $policy = $data['policy'];
        foreach ($policy as $key => $value) {
            $policy[$key] = $key === 'gps_enabled' ? (bool) $value : ($key === 'arrival_samples' ? (int) $value : (float) $value);
        }
        if ($policy['arrival_radius_m'] >= $policy['warning_uncertainty_m']
            || $policy['snap_distance_m'] > $policy['warning_uncertainty_m']
            || $policy['gps_arrival_accuracy_m'] > $policy['gps_progress_accuracy_m']
            || $policy['gps_progress_accuracy_m'] > $policy['gps_max_accuracy_m']) {
            throw ValidationException::withMessages(['policy' => 'Inconsistent navigation thresholds']);
        }
        return DB::transaction(function () use ($data, $policy) {
            $row = DB::table('routing_timing_settings')->where('singleton', true)->lockForUpdate()->first();
            abort_unless($row, 503, 'Navigation settings unavailable');
            abort_if((int) $row->navigation_version !== (int) $data['version'], 409, 'Settings changed; reload before saving');
            DB::table('routing_timing_settings')->where('singleton', true)->update([
                'navigation_policy' => json_encode($policy, JSON_THROW_ON_ERROR),
                'navigation_version' => $row->navigation_version + 1,
                'navigation_updated_at' => now(), 'walk_speed_mps' => (float) $data['walk_speed_mps'],
            ]);
            return $this->show();
        });
    }
}
