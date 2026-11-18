<?php

declare(strict_types=1);

namespace App\Tantra\Embed;

use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Http\Request;

/**
 * tantra: the engine as Tantra shows it (AF-10, AF-04; Adi 18 Nov). A session started by the Tantra embed (EmbedController)
 * is marked, so the pages it opens hide what belongs to the engine product and not to Tantra: the engine name and logo,
 * the "Connect Claude or ChatGPT" prompt, engine Members, Workspaces, "Invite your team" and "Delete workspace". The
 * Vue side reads the shared prop tantraView (and its own frame check); a page opened in its own tab is unchanged.
 *
 * Time zone: Tantra's workspace time zone, sent with provisioning (PV-02) or the embed session, replaces the engine
 * default a provisioned person was given (ENGINE_DEFAULTS). Any other time zone on the person is left as it is.
 */
final class TantraView
{
    public const SESSION = 'tantra.embed';

    /** A sibling key, not tantra.embed.timezone: the session store reads dots as nesting, and that would turn SESSION into an array. */
    public const SESSION_TIMEZONE = 'tantra.embed_timezone';

    /** What the engine gives a provisioned person before Tantra says otherwise. */
    public const ENGINE_DEFAULTS = ['Asia/Kolkata', 'UTC'];

    /** A real IANA time zone name, or null. */
    public static function timezone(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 64) {
            return null;
        }

        return in_array($value, timezone_identifiers_list(), true) ? $value : null;
    }

    /** Mark this browser's session as the Tantra embed, and give the person Tantra's time zone where they still have the engine's. */
    public static function mark(Request $request, User $user, mixed $timezone = null): void
    {
        $tz = self::timezone($timezone) ?? self::workspaceTimezone($user->currentWorkspace);
        $request->session()->put(self::SESSION, true);
        if ($tz !== null) {
            $request->session()->put(self::SESSION_TIMEZONE, $tz);
            self::adopt($user, $tz);
        }
    }

    public static function active(?Request $request): bool
    {
        return $request !== null && $request->hasSession() && $request->session()->get(self::SESSION) === true;
    }

    /** The props the Vue side reads: whether this is the Tantra view, and Tantra's time zone. */
    public static function props(?Request $request): array
    {
        $active = self::active($request);

        return [
            'active' => $active,
            'timezone' => $active ? self::timezone($request->session()->get(self::SESSION_TIMEZONE)) : null,
        ];
    }

    /** The Tantra time zone stored for an engine workspace (by provisioning), or null. */
    public static function workspaceTimezone(?Workspace $workspace): ?string
    {
        return $workspace === null ? null : self::timezone(WorkspaceSettings::query()->find($workspace->id)?->timezone);
    }

    /** Store Tantra's time zone for an engine workspace (provisioning). */
    public static function rememberWorkspaceTimezone(Workspace $workspace, mixed $timezone): ?string
    {
        $tz = self::timezone($timezone);
        if ($tz !== null) {
            $settings = WorkspaceSettings::query()->firstOrNew(['workspace_id' => $workspace->id]);
            if ($settings->timezone !== $tz) {
                $settings->forceFill(['timezone' => $tz])->save();
            }
        }

        return $tz;
    }

    /** The person gets Tantra's time zone only while they still have an engine default. */
    public static function adopt(User $user, ?string $tz): void
    {
        if ($tz === null || $user->timezone === $tz) {
            return;
        }
        if ($user->timezone === null || in_array($user->timezone, self::ENGINE_DEFAULTS, true)) {
            $user->forceFill(['timezone' => $tz])->save();
        }
    }
}
