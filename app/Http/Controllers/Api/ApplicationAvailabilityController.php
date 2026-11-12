<?php

namespace App\Http\Controllers\Api;

use App\Actions\Application\SwapApplicationPrimary;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\StandaloneDocker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Automatic failover, uptime and outage history of applications, and swapping the primary server.
 */
#[OA\Schema(
    schema: 'ApplicationAvailability',
    description: 'Automatic failover state, uptime, and outages of the last 30 days of an application.',
    type: 'object',
    allOf: [
        new OA\Schema(properties: [
            new OA\Property(property: 'is_auto_failover_enabled', type: 'boolean'),
            new OA\Property(property: 'auto_failover_unavailable_reason', type: 'string', nullable: true, description: 'Why automatic failover cannot work now. Null when it can.'),
            new OA\Property(property: 'primary_server_uuid', type: 'string', nullable: true),
        ]),
        new OA\Schema(ref: '#/components/schemas/Availability'),
    ],
)]
class ApplicationAvailabilityController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    private function applicationAvailability(Application $application): array
    {
        return [
            'is_auto_failover_enabled' => (bool) $application->settings?->is_auto_failover_enabled,
            'auto_failover_unavailable_reason' => $application->autoFailoverUnavailableReason(),
            'primary_server_uuid' => $application->destination?->server?->uuid,
            ...$application->availabilityReport(),
        ];
    }

    #[OA\Get(
        summary: 'Get application availability',
        description: 'Automatic failover state, uptime percentages of the last 24 hours, 7 days, and 30 days, and the outages of the last 30 days. Outages are only recorded while automatic failover is on. An outage starts when the primary server stops running the application.',
        path: '/applications/{uuid}/availability',
        operationId: 'get-application-availability',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the application.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Application availability.', content: new OA\JsonContent(ref: '#/components/schemas/ApplicationAvailability')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->route('uuid'))->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $this->authorize('view', $application);

        return response()->json($this->applicationAvailability($application));
    }

    #[OA\Patch(
        summary: 'Update automatic failover',
        description: 'Turn automatic failover of an application on or off. It needs at least one additional destination and a Docker registry image.',
        path: '/applications/{uuid}/availability',
        operationId: 'update-application-availability',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the application.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['is_auto_failover_enabled'],
                properties: [
                    new OA\Property(property: 'is_auto_failover_enabled', type: 'boolean', description: 'Make an additional server primary when the primary server goes down.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Application availability.', content: new OA\JsonContent(ref: '#/components/schemas/ApplicationAvailability')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function update(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->all(), [
            'is_auto_failover_enabled' => 'required|boolean',
        ]);
        $extraFields = array_diff(array_keys($request->all()), ['is_auto_failover_enabled']);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->route('uuid'))->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $this->authorize('update', $application);

        $enabled = $request->boolean('is_auto_failover_enabled');
        if ($enabled && ($reason = $application->autoFailoverUnavailableReason())) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['is_auto_failover_enabled' => [$reason]],
            ], 422);
        }

        $application->settings->update(['is_auto_failover_enabled' => $enabled]);
        auditLog('api.application.auto_failover_updated', [
            'team_id' => $teamId,
            'application_uuid' => $application->uuid,
            'application_name' => $application->name,
            'is_auto_failover_enabled' => $enabled,
        ]);

        return response()->json($this->applicationAvailability($application->refresh()));
    }

    #[OA\Post(
        summary: 'Swap primary destination',
        description: 'Make an additional destination the primary destination of an application. The old primary destination becomes an additional one. Coolify-managed DNS records of the application move to the new primary server.',
        path: '/applications/{uuid}/destinations/{destination_uuid}/swap',
        operationId: 'swap-application-destination',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the application.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'destination_uuid', in: 'path', required: true, description: 'UUID of the additional destination.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The destination is primary now.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'dns_repointed', type: 'array', items: new OA\Items(type: 'string'), description: 'Hostnames whose managed DNS record points to the new primary server now.'),
                        new OA\Property(property: 'dns_failed', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'), description: 'Hostnames whose managed DNS record could not be changed, with the error.'),
                        new OA\Property(property: 'dns_unmanaged', type: 'array', items: new OA\Items(type: 'string'), description: 'Hostnames without a Coolify-managed DNS record.'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function swap(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->route('uuid'))->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $this->authorize('update', $application);

        $destination = StandaloneDocker::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->route('destination_uuid'))->first();
        if (! $destination || ! $application->additional_networks()->wherePivot('server_id', $destination->server_id)->whereKey($destination->id)->exists()) {
            return response()->json(['message' => 'Destination not found.'], 404);
        }

        $server = $destination->server;
        if (! $server->isFunctional() || $server->settings->is_swarm_worker || ! $server->canHostResources()) {
            return response()->json(['message' => "The server {$server->name} cannot host the application right now."], 422);
        }

        $dns = SwapApplicationPrimary::run($application, $destination, 'api');

        return response()->json([
            'message' => "{$server->name} is the primary server now.",
            'dns_repointed' => $dns['repointed'],
            'dns_failed' => (object) $dns['failed'],
            'dns_unmanaged' => $dns['unmanaged'],
        ]);
    }
}
