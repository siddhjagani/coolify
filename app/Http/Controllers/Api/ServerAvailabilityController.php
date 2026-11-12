<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Availability',
    description: 'Uptime and outages of the last 30 days.',
    type: 'object',
    properties: [
        new OA\Property(property: 'uptime_percentage', type: 'object', properties: [
            new OA\Property(property: '24h', type: 'number', format: 'float'),
            new OA\Property(property: '7d', type: 'number', format: 'float'),
            new OA\Property(property: '30d', type: 'number', format: 'float'),
        ]),
        new OA\Property(property: 'incidents', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'reason', type: 'string', enum: ['unreachable', 'docker_unavailable', 'server_down', 'not_running']),
            new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'ended_at', type: 'string', format: 'date-time', nullable: true, description: 'Null while the outage goes on.'),
            new OA\Property(property: 'duration_seconds', type: 'integer'),
        ])),
    ],
)]
class ServerAvailabilityController extends Controller
{
    #[OA\Get(
        summary: 'Get server availability',
        description: 'Uptime percentages of the last 24 hours, 7 days, and 30 days, and the outages of the last 30 days. An outage starts when Coolify marks the server as unreachable or Docker as unavailable.',
        path: '/servers/{uuid}/availability',
        operationId: 'get-server-availability',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Server availability.', content: new OA\JsonContent(ref: '#/components/schemas/Availability')),
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

        $server = Server::whereTeamId($teamId)->whereUuid($request->route('uuid'))->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('view', $server);

        return response()->json($server->availabilityReport());
    }
}
