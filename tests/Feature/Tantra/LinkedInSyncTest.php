<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Boost\MemberProfile;
use App\Tantra\LinkedIn\Identity;
use App\Tantra\LinkedIn\SyncLinkedInIdentity;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const LI_JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

beforeEach(function () {
    Storage::fake(config('filesystems.default'));
    config(['tantra.unipile.dsn' => 'https://unipile.test/v2', 'tantra.unipile.key' => 'k']);
    $result = createApiTestToken();
    $this->adi = $result['user'];
    $this->ben = User::factory()->create(['account_id' => $this->adi->account_id, 'name' => 'Ben Basil Tomy']);
    $this->twin = User::factory()->create(['account_id' => $this->adi->account_id, 'name' => 'Sam Lee']);
    User::factory()->create(['account_id' => $this->adi->account_id, 'name' => 'sam  lee']);
    Identity::flush();
});

function fakeLinkedIn(array $me = [], int $status = 200): void
{
    Http::fake([
        'unipile.test/v2/accounts*' => Http::response(['items' => [
            ['id' => 'acc_ben', 'name' => 'Ben Basil Tomy', 'provider' => 'LINKEDIN', 'status' => 'OK'],
            ['id' => 'acc_sam', 'name' => 'Sam Lee', 'provider' => 'LINKEDIN', 'status' => 'OK'],
            ['id' => 'acc_wa', 'name' => 'Ben Basil Tomy', 'provider' => 'WHATSAPP', 'status' => 'OK'],
        ]]),
        'unipile.test/v2/acc_ben/users/me' => Http::response($me ?: [
            'id' => 'ACoAABen', 'display_name' => 'Ben Basil Tomy', 'description' => 'Backend Engineer | Node.js',
            'public_identifier' => 'ben', 'profile_url' => 'https://www.linkedin.com/in/ben', 'followers_count' => 512,
            'public_picture_url_large' => 'https://media.licdn.test/ben.jpg?e=1&t=a',
        ], $status),
        'media.licdn.test/*' => Http::response(base64_decode(LI_JPEG), 200, ['Content-Type' => 'image/jpeg']),
    ]);
}

it('matches a LinkedIn account to the teammate with the same name, without confirming it', function () {
    fakeLinkedIn();

    $this->artisan('tantra:linkedin-sync')->assertSuccessful();

    $ben = MemberProfile::query()->find($this->ben->id);
    expect($ben->unipile_account_id)->toBe('acc_ben')
        ->and($ben->unipile_match_method)->toBe('name')
        ->and($ben->unipile_confirmed_at)->toBeNull()
        ->and($ben->unipile_status)->toBe('connected');
    // Two Engine users called Sam Lee: Adi decides on the Team page.
    expect(MemberProfile::query()->where('unipile_account_id', 'acc_sam')->exists())->toBeFalse();
});

it('pulls the name, headline, photo and member id from LinkedIn and makes the photo theirs', function () {
    fakeLinkedIn();

    $this->artisan('tantra:linkedin-sync')->assertSuccessful();

    $ben = MemberProfile::query()->find($this->ben->id);
    expect($ben)
        ->linkedin_name->toBe('Ben Basil Tomy')
        ->linkedin_headline->toBe('Backend Engineer | Node.js')
        ->linkedin_member_id->toBe('ACoAABen')
        ->linkedin_profile_url->toBe('https://www.linkedin.com/in/ben')
        ->linkedin_followers->toBe(512)
        ->linkedin_picture_path->toBe("tantra/linkedin/{$this->ben->id}.jpg");
    Storage::assertExists("tantra/linkedin/{$this->ben->id}.jpg");
    expect($this->ben->refresh()->has_photo)->toBeTrue()
        ->and($this->ben->getFirstMedia('avatar')->meta['source'] ?? null)->toBe('linkedin');
});

it('reads a person again only once a day, and keeps the photo when only its link changed', function () {
    $me = ['id' => 'ACoAABen', 'display_name' => 'Ben Basil Tomy', 'description' => 'Backend Engineer', 'public_picture_url_large' => 'https://media.licdn.test/ben.jpg?e=1&t=a'];
    Http::fake(function (Request $r) use (&$me) {
        return match (true) {
            str_contains($r->url(), '/accounts') => Http::response(['items' => [['id' => 'acc_ben', 'name' => 'Ben Basil Tomy', 'provider' => 'LINKEDIN', 'status' => 'OK']]]),
            str_contains($r->url(), 'users/me') => Http::response($me),
            default => Http::response(base64_decode(LI_JPEG), 200, ['Content-Type' => 'image/jpeg']),
        };
    });
    $calls = fn (string $part): int => collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), $part))->count();

    $this->artisan('tantra:linkedin-sync')->assertSuccessful();
    $this->artisan('tantra:linkedin-sync')->assertSuccessful();
    expect($calls('users/me'))->toBe(1)->and($calls('media.licdn.test'))->toBe(1);

    $this->travel(25)->hours();
    $me = [...$me, 'description' => 'Staff Engineer', 'public_picture_url_large' => 'https://media.licdn.test/ben.jpg?e=2&t=b'];
    $this->artisan('tantra:linkedin-sync')->assertSuccessful();

    expect(MemberProfile::query()->find($this->ben->id)->linkedin_headline)->toBe('Staff Engineer')
        ->and($calls('users/me'))->toBe(2)
        ->and($calls('media.licdn.test'))->toBe(1);
});

it('marks a LinkedIn account Unipile lost as disconnected', function () {
    fakeLinkedIn(['title' => 'Account is disconnected.'], 401);

    $this->artisan('tantra:linkedin-sync')->assertSuccessful();

    expect(MemberProfile::query()->find($this->ben->id))
        ->unipile_status->toBe('disconnected')
        ->linkedin_synced_at->toBeNull();
});

it('leaves a photo the person uploaded themself', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'own');
    file_put_contents($tmp, base64_decode(LI_JPEG));
    $this->ben->addMediaFromPath($tmp, 'me.jpg', 'avatar', [], null, 'image/jpeg');
    $own = $this->ben->getFirstMedia('avatar')->id;
    fakeLinkedIn();

    $this->artisan('tantra:linkedin-sync')->assertSuccessful();

    expect($this->ben->refresh()->getFirstMedia('avatar')->id)->toBe($own)
        ->and(MemberProfile::query()->find($this->ben->id)->linkedin_picture_path)->not->toBeNull();
});

it('takes the page\'s name, logo and followers from Zernio and shows them for page posts', function () {
    config(['tantra.zernio.key' => 'zk', 'tantra.zernio.url' => 'https://zernio.test/api/v1']);
    Http::fake([
        'zernio.test/api/v1/accounts*' => Http::response(['accounts' => [[
            '_id' => 'zacc', 'platform' => 'linkedin', 'displayName' => 'Tantra', 'profilePicture' => 'https://media.licdn.test/logo.png?e=1',
            'profileUrl' => 'https://www.linkedin.com/company/usetantra', 'followersCount' => 8814,
        ]]]),
        'media.licdn.test/*' => Http::response(base64_decode(LI_JPEG), 200, ['Content-Type' => 'image/jpeg']),
        'unipile.test/*' => Http::response(['items' => []]),
    ]);
    $tantra = Workspace::factory()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->adi->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $tantra->id, 'brand_profile_key' => 'tantra', 'review_owner_user_id' => $this->adi->id]);
    $page = SocialAccount::factory()->create(['workspace_id' => $tantra->id, 'platform' => Platform::ZernioLinkedInPage, 'platform_user_id' => 'zacc', 'display_name' => 'old', 'access_token' => 'zernio-managed']);

    $this->artisan('tantra:linkedin-sync')->assertSuccessful();

    $page->refresh();
    expect($page->display_name)->toBe('Tantra')
        ->and($page->avatar_url)->toBe(Storage::url("tantra/linkedin/page-{$page->id}.jpg"))
        ->and($page->meta['linkedin_followers'])->toBe(8814);

    Identity::flush();
    expect(Identity::forKey($this->adi->account_id, 'tantra'))
        ->toMatchArray(['name' => 'Tantra', 'kind' => 'company', 'followers_line' => '8,814 followers']);
});

it('reads a person at once when Adi matches their account, and forgets the old one\'s identity', function () {
    Bus::fake([SyncLinkedInIdentity::class]);
    fakeLinkedIn();
    MemberProfile::query()->create(['user_id' => $this->ben->id, 'unipile_account_id' => 'acc_old', 'linkedin_name' => 'Someone Else', 'linkedin_synced_at' => now()]);

    $this->actingAs($this->adi)->postJson(route('tantra.team.match'), ['user_id' => $this->ben->id, 'unipile_account_id' => 'acc_ben'])->assertOk();

    Bus::assertDispatched(SyncLinkedInIdentity::class, fn (SyncLinkedInIdentity $job) => $job->userId === $this->ben->id);
    expect(MemberProfile::query()->find($this->ben->id))
        ->unipile_account_id->toBe('acc_ben')
        ->linkedin_name->toBeNull();
});
