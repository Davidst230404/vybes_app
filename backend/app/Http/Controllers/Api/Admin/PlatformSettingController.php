<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Http\Resources\Admin\PlatformSettingResource;
use App\Models\PlatformSetting;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlatformSettingController extends Controller
{
    /**
     * List all platform settings.
     */
    public function index(): AnonymousResourceCollection
    {
        $settings = PlatformSetting::orderBy('id')->get();

        return PlatformSettingResource::collection($settings);
    }

    /**
     * Update platform settings.
     */
    public function update(UpdatePlatformSettingsRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validated();
        $updated = [];

        foreach ($validated as $key => $val) {
            $setting = PlatformSetting::where('key', $key)->first();
            $oldValue = $setting?->value;

            $type = $setting?->type ?? 'string';
            $saved = PlatformSetting::set($key, $val, $type, $setting?->description);
            $updated[] = $saved;

            $auditLogger->log(
                actor: $request->user(),
                action: 'platform_setting.update',
                entityType: 'platform_setting',
                entityId: $saved->id,
                metadata: [
                    'key' => $key,
                    'old_value' => $oldValue,
                    'new_value' => $val,
                ]
            );
        }

        return response()->json([
            'message' => 'Platform settings updated successfully.',
            'data' => PlatformSettingResource::collection(PlatformSetting::orderBy('id')->get()),
        ]);
    }
}
