<?php

declare(strict_types=1);

// tantra: AF-10, what the engine pages hide inside Tantra, read from the Vue source (useTantraView).

function tantraViewSource(string $file): string
{
    return (string) file_get_contents(resource_path("js/{$file}"));
}

it('hides the engine name and logo inside Tantra', function () {
    expect(tantraViewSource('components/AppLogo.vue'))->toContain('v-else-if="!inTantra"')
        ->and(tantraViewSource('components/analytics/workspace/TopPosts.vue'))->toContain("inTantra ? 'Tantra' : 'TryPost'")
        ->and(tantraViewSource('app.ts'))->toContain("? 'Tantra'");
});

it('hides the Connect Claude or ChatGPT prompt inside Tantra', function () {
    expect(tantraViewSource('components/mcp/McpPromptCard.vue'))->toContain('!inTantra.value &&');
});

it('hides Members, Workspaces, Invite your team and Delete workspace inside Tantra', function () {
    expect(tantraViewSource('composables/tantra/useTantraView.ts'))->toContain("HIDDEN_IN_TANTRA = ['members', 'workspaces']")
        ->and(tantraViewSource('composables/useSettingsNavigation.ts'))->toContain('!HIDDEN_IN_TANTRA.includes(navItem.name)')
        ->and(tantraViewSource('composables/useSettingsNavigation.ts'))->not->toContain("name: 'workspaces'")
        ->and(tantraViewSource('components/WorkspaceMenuContent.vue'))->toContain('<DropdownMenuSub v-if="!inTantra">')
        ->and(tantraViewSource('components/WorkspaceMenuContent.vue'))->toContain('v-if="canManageTeam && !inTantra"')
        ->and(tantraViewSource('components/AppSidebar.vue'))->toContain('v-if="canManageTeam && !inTantra"')
        ->and(tantraViewSource('pages/publish/Index.vue'))->toContain('v-if="canManageTeam && !inTantra"')
        ->and(tantraViewSource('components/settings/WorkspaceTab.vue'))->toContain('v-if="canManageBilling && !inTantra"');
});

it('reloads once on a missing chunk after a deploy, guarded by sessionStorage', function () {
    $app = tantraViewSource('app.ts');

    expect($app)->toContain("addEventListener('vite:preloadError'")
        ->and($app)->toContain('sessionStorage.getItem(PRELOAD_RELOAD_KEY)')
        ->and($app)->toContain('sessionStorage.setItem(PRELOAD_RELOAD_KEY')
        ->and($app)->toContain('window.location.reload()');
});
