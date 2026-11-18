<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tantra\Provision\NoOwner;
use App\Tantra\Provision\ProvisionTantraTeam;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * tantra: PV-02. The Tantra app sends a team and its people, server to server; the Engine finds or makes its workspace and users
 * (tantra-crm docs/crm/engine-patches/pv-02/README.md). The answer carries counts only: no engine id, token or email.
 *
 *   POST provision
 */
class TantraProvisionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'workspace' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:120'],
            'people' => ['present', 'array', 'max:500'],
            'people.*.clerk_user_id' => ['required', 'string', 'max:100', 'distinct'],
            'people.*.email' => ['required', 'email', 'max:255'],
            'people.*.name' => ['nullable', 'string', 'max:120'],
            'people.*.role' => ['required', 'in:owner,admin,member'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone:all'],
        ], [
            'people.*.role.in' => 'A person\'s role must be owner, admin or member.',
            'people.*.clerk_user_id.distinct' => 'Each person may be listed only once.',
        ]);
        $validator->after(function ($v) use ($request): void {
            $owners = collect((array) $request->input('people', []))->filter(fn ($p): bool => is_array($p) && ($p['role'] ?? null) === 'owner')->count();
            if ($owners > 1) {
                $v->errors()->add('people', 'A team has at most one owner.');
            }
        });
        if ($validator->fails()) {
            return $this->fail(422, $validator->errors()->first(), 'validation');
        }

        $data = $validator->validated();
        try {
            $result = ProvisionTantraTeam::execute($data['workspace'], $data['name'], $data['people'], $data['timezone'] ?? null);
        } catch (NoOwner) {
            return $this->fail(422, 'A new team needs an owner before it can be set up.', 'no_owner');
        } catch (QueryException $e) {
            if (preg_match('/lock|deadlock|timeout|55P03|40P01/i', $e->getMessage()) === 1) {
                return $this->fail(409, 'The team is being set up right now; try again.', 'busy');
            }

            throw $e;
        }

        return response()->json(['ok' => true, ...$result])->header('Cache-Control', 'no-store, private');
    }

    private function fail(int $status, string $message, string $code): JsonResponse
    {
        return response()->json(['error' => $message, 'code' => $code], $status)->header('Cache-Control', 'no-store, private');
    }
}
