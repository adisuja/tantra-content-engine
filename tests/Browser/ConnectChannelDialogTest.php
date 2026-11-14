<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Laravel\Socialite\Facades\Socialite;

/**
 * Every network's consent screen is replaced by a page of the app that says
 * which driver it was, so a started connection never leaves the test.
 */
function stubConnectDialogProviders(): void
{
    Socialite::shouldReceive('driver')->andReturnUsing(function (string $driver): object {
        $stub = Mockery::mock();
        $stub->shouldReceive('redirect')->andReturn(Mockery::mock([
            'getTargetUrl' => route('app.workspace.channels', ['reached' => $driver]),
        ]));
        $stub->shouldIgnoreMissing($stub);

        return $stub;
    });
}

function connectDialogReachedProvider(mixed $page): ?string
{
    return $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 100; i++) {
                const reached = new URLSearchParams(window.location.search).get('reached');
                if (reached) return reached;
                await new Promise((r) => setTimeout(r, 50));
            }
            return null;
        })();
    JS);
}

function connectDialogAdmin(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

/**
 * tantra: the "Connect a channel" logo grid, opened from the sidebar's add
 * button on a page without an inline grid (Adi 2026-11-14).
 */
function openConnectDialogGrid(): mixed
{
    $page = visit(route('app.posts.index'));
    waitFor($page, '@sidebar-channels-connect');
    $page->click('@sidebar-channels-connect');
    waitFor($page, '@connect-grid');

    return $page;
}

test('the add button opens the logo grid', function () {
    $this->actingAs(connectDialogAdmin());

    $page = openConnectDialogGrid();

    $page->assertSeeIn('@connect-channel-dialog', 'Connect a channel')
        ->assertVisible('@connect-channel-linkedin')
        ->assertVisible('@connect-channel-instagram')
        ->assertVisible('@connect-channel-bluesky')
        ->assertNoJavaScriptErrors();

    // A tile is only a logo and a name: no developer words anywhere.
    $text = $page->script('document.querySelector(\'[data-testid="connect-channel-dialog"]\').innerText');
    expect($text)->not->toContain('developer')->not->toContain('Developer')->not->toContain('keys')->not->toContain('TryPost');
});

test('an oauth tile opens its pop-up, goes back to the grid, and continues to the consent screen', function () {
    $this->actingAs(connectDialogAdmin());
    stubConnectDialogProviders();

    $page = openConnectDialogGrid();
    $page->click('@connect-channel-linkedin');
    waitFor($page, '@connect-panel-continue');

    $page->assertSeeIn('@connect-panel-title', 'Connect LinkedIn')
        ->assertSeeIn('@connect-panel-continue', 'Continue with LinkedIn')
        ->assertMissing('@connect-grid');

    $page->click('@connect-channel-back');
    waitFor($page, '@connect-grid');
    $page->click('@connect-channel-linkedin');
    waitFor($page, '@connect-panel-continue');
    $page->click('@connect-panel-continue');

    expect(connectDialogReachedProvider($page))->toBe('linkedin-openid');
    $page->assertMissing('@connect-channel-dialog')->assertNoJavaScriptErrors();
});

test('instagram asks in plain words which way the account signs in', function () {
    $this->actingAs(connectDialogAdmin());
    stubConnectDialogProviders();

    $page = openConnectDialogGrid();
    $page->click('@connect-channel-instagram');
    waitFor($page, '@connect-panel-choices');

    $page->assertVisible('@connect-choice-instagram')
        ->assertVisible('@connect-choice-instagram-facebook')
        ->assertSeeIn('@connect-panel-continue', 'Continue with Instagram');

    $page->click('@connect-choice-instagram-facebook');
    waitForState($page, '@connect-panel-continue', "element.textContent.includes('Continue with Facebook')");
    $page->click('@connect-panel-continue');

    expect(connectDialogReachedProvider($page))->toBe('facebook');
    $page->assertNoJavaScriptErrors();
});

test('bluesky asks only for the handle and an app password, with a link to create one', function () {
    $this->actingAs(connectDialogAdmin());

    $page = openConnectDialogGrid();
    $page->click('@connect-channel-bluesky');
    waitFor($page, '@connect-panel-bluesky');

    $page->assertVisible('@connect-bluesky-handle')
        ->assertVisible('@connect-bluesky-password')
        ->assertMissing('@connect-panel-continue');

    $link = $page->script(<<<'JS'
        (() => {
            const link = document.querySelector('[data-testid="connect-bluesky-app-password-link"]');

            return { href: link.href, target: link.target, text: link.textContent.trim() };
        })();
    JS);

    expect($link)->toBe(['href' => 'https://bsky.app/settings/app-passwords', 'target' => '_blank', 'text' => 'Create an app password']);
    $page->assertNoJavaScriptErrors();
});

test('a network that cannot connect yet is muted and not a button', function () {
    config()->set('trypost.platforms.pinterest.enabled', false);
    $this->actingAs(connectDialogAdmin());

    $page = openConnectDialogGrid();

    $tile = $page->script(<<<'JS'
        (() => {
            const tile = document.querySelector('[data-testid="connect-channel-pinterest"]');

            return { tag: tile.tagName, ready: tile.dataset.ready, text: tile.innerText.replace(/\s+/g, ' ').trim() };
        })();
    JS);

    expect($tile['tag'])->toBe('DIV')
        ->and($tile['ready'])->toBe('false')
        ->and($tile['text'])->toStartWith('Pinterest');
    $page->assertNoJavaScriptErrors();
});

function openTelegramConnectStep(): mixed
{
    $page = openConnectDialogGrid();
    $page->script('window.__copied = []; navigator.clipboard.writeText = async (text) => { window.__copied.push(text); }; true;');
    $page->click('@connect-channel-telegram');
    waitFor($page, '@telegram-connect-command');

    return $page;
}

test('the telegram step shows its instructions, copies and regenerates the command, links the help and asks before closing', function () {
    config()->set('trypost.platforms.telegram.bot_username', 'TryPost_Bot');
    $this->actingAs(connectDialogAdmin());

    $page = openTelegramConnectStep();

    $page->assertMissing('@connect-channel-logos')
        ->assertSee(trans('accounts.telegram.title'))
        ->assertSee(trans('accounts.telegram.description'))
        ->assertSeeIn('@telegram-connect-steps-title', trans('accounts.telegram.steps'))
        ->assertSeeIn('@telegram-connect-bot', '@TryPost_Bot')
        ->assertSeeIn('@telegram-connect-waiting', trans('accounts.telegram.waiting'))
        ->assertVisible('@telegram-connect-regenerate')
        ->assertNoJavaScriptErrors();

    $command = $page->script('document.querySelector(\'[data-testid="telegram-connect-command"]\').textContent.trim()');
    expect($command)->toStartWith('/connect ');

    $openBot = $page->script(<<<'JS'
        (() => {
            const link = document.querySelector('[data-testid="telegram-connect-open-bot"]');

            return { href: link.href, target: link.target };
        })();
    JS);

    expect($openBot)->toBe(['href' => 'https://t.me/TryPost_Bot', 'target' => '_blank']);

    $live = $page->script('document.querySelector(\'[data-testid="telegram-connect-waiting"]\').parentElement.getAttribute("aria-live")');
    expect($live)->toBe('polite');

    $copiedCommand = $page->script('document.querySelector(\'[data-testid="telegram-connect-command"]\').textContent.trim()');

    $page->click('@telegram-connect-copy');
    waitFor($page, '@telegram-connect-copied');

    $page->assertSeeIn('@telegram-connect-copied', trans('common.actions.copied'))
        ->assertMissing('@telegram-connect-copy')
        ->assertNoJavaScriptErrors();

    expect($page->script('window.__copied'))->toBe([$copiedCommand])
        ->and($page->script('document.querySelectorAll("[data-sonner-toast]").length'))->toBe(0);

    $first = $page->script('document.querySelector(\'[data-testid="telegram-connect-command"]\').textContent.trim()');

    $page->click('@telegram-connect-regenerate');
    waitForState($page, '@telegram-connect-command', "element.textContent.trim() !== '{$first}'");

    $second = $page->script('document.querySelector(\'[data-testid="telegram-connect-command"]\').textContent.trim()');

    expect($second)->toStartWith('/connect ')->not->toBe($first);
    $page->assertVisible('@telegram-connect-waiting')->assertNoJavaScriptErrors();

    $page->click('@telegram-connect-help');
    waitFor($page, '@telegram-connect-help-bot-privacy');

    $links = $page->script(<<<'JS'
        (() => ['channel-admins', 'group-admins', 'bot-privacy'].map((key) => {
            const link = document.querySelector(`[data-testid="telegram-connect-help-${key}"]`);

            return { host: new URL(link.href).host, target: link.target, text: link.textContent.trim() };
        }))();
    JS);

    expect($links)->toBe([
        ['host' => 'telegram.org', 'target' => '_blank', 'text' => trans('accounts.telegram.help.channel_admins')],
        ['host' => 'telegram.org', 'target' => '_blank', 'text' => trans('accounts.telegram.help.group_admins')],
        ['host' => 'telegram.org', 'target' => '_blank', 'text' => trans('accounts.telegram.help.bot_privacy')],
    ]);
    $page->assertNoJavaScriptErrors();

    $page->keys('@telegram-connect-help', 'Escape');
    waitForGone($page, '@telegram-connect-help-bot-privacy');

    $page->keys('@connect-channel-back', 'Escape');
    waitFor($page, '@connect-channel-exit-confirm');

    $page->assertSeeIn('@connect-channel-exit-confirm', trans('channels.dialog.exit_confirm.title', ['network' => 'Telegram']));

    $page->keys('@connect-channel-exit-continue', 'Escape');
    waitFor($page, '@connect-channel-back');

    $page->assertMissing('@connect-channel-exit-confirm')
        ->assertVisible('@connect-channel-dialog')
        ->assertVisible('@connect-channel-back');

    $page->keys('@connect-channel-back', 'Escape');
    waitFor($page, '@connect-channel-exit-leave');
    $page->click('@connect-channel-exit-leave');

    $page->assertMissing('@connect-channel-dialog')->assertNoJavaScriptErrors();
});

test('closing the logo grid does not ask for confirmation', function () {
    $this->actingAs(connectDialogAdmin());

    $page = openConnectDialogGrid();
    $page->click('@dialog-close');

    $page->assertMissing('@connect-channel-dialog')
        ->assertMissing('@connect-channel-exit-confirm')
        ->assertNoJavaScriptErrors();
});
