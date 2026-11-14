<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Actions\ReviewActions;
use App\Tantra\Review\Enums\ReviewStatus;
use App\Tantra\Review\Messages;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

// tantra: links to one post, the public asset preview, and the follow-up messages.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00:00', 'UTC'));

    $result = createApiTestToken();
    $this->owner = $result['user'];
    $this->adi = $result['workspace'];
    $this->headers = ['Authorization' => 'Bearer '.$result['plain_token']];

    $this->teammate = User::factory()->create(['account_id' => $this->owner->account_id]);
    $this->team = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->teammate->id]);
    $this->team->members()->attach($this->teammate->id, membershipPivot('admin'));
    $this->teammate->update(['current_workspace_id' => $this->team->id]);

    $this->outsider = User::factory()->create(['account_id' => $this->owner->account_id]);
    $other = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->outsider->id]);
    $other->members()->attach($this->outsider->id, membershipPivot('admin'));
    $this->outsider->update(['current_workspace_id' => $other->id]);

    WorkspaceSettings::query()->create(['workspace_id' => $this->adi->id, 'brand_profile_key' => 'adi', 'review_owner_user_id' => $this->owner->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $this->team->id, 'brand_profile_key' => 'mate', 'review_owner_user_id' => $this->teammate->id]);

    $weekdays = PostingSchedule::empty();
    foreach ([1, 2, 3, 4, 5] as $day) {
        $weekdays = $weekdays->withTime($day, '08:30');
    }
    foreach ([$this->adi, $this->team] as $workspace) {
        SocialAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'platform' => Platform::LinkedIn,
            'timezone' => 'America/New_York',
            'posting_schedule' => $weekdays,
        ]);
    }

    $this->push = fn (array $mate = []) => ImportBatch::execute($this->owner, [
        'engine_batch_id' => '4',
        'title' => 'Batch 4',
        'posts' => [
            ['engine_post_id' => 'A1', 'profile' => 'adi', 'platform' => 'linkedin', 'date' => '2026-11-24', 'time' => '08:30', 'tz' => 'America/New_York', 'text' => 'Adi post copy.'],
            [
                'engine_post_id' => 'M1',
                'profile' => 'mate',
                'platform' => 'linkedin',
                'date' => '2026-11-24',
                'time' => '08:30',
                'tz' => 'America/New_York',
                'text' => 'Comment MATH and I will send it.',
                'resource' => [
                    'name' => 'The Revenue Equation Sheet',
                    'keyword' => 'MATH',
                    'what' => 'One page with the four numbers.',
                    'url' => 'https://example.com/sheet/',
                    'files' => [['label' => 'PDF', 'href' => 'https://example.com/sheet.pdf']],
                ],
                'resource_preview_paths' => ['tantra/previews/w/4/M1/r01.jpg', 'tantra/previews/w/4/M1/r02.jpg'],
                'messages' => [
                    'linkedin_dm' => "Hi {first_name}, here's the sheet:\nhttps://example.com/sheet/",
                    'email_subject' => 'Your Revenue Equation Sheet',
                    'email_body' => "Hi {first_name},\n\nYou commented on [POST LINK]. Here it is.",
                    'whatsapp' => 'Hi {first_name}, the sheet: https://example.com/sheet/',
                    'sms' => 'Hi {first_name}, the sheet: https://example.com/sheet/',
                ],
                ...$mate,
            ],
        ],
    ]);
    ($this->push)();
    $this->item = ReviewItem::query()->where('engine_post_id', 'M1')->sole();
});

it('opens a link to one post for its reviewer, even after signing in', function () {
    $url = $this->item->shareUrl();
    expect($url)->toContain('item='.$this->item->id);

    $this->get($url)->assertRedirect(route('login'));

    $this->actingAs($this->teammate)->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tantra/review/Index')
            ->where('tab', 'review')
            ->where('focus', $this->item->id)
            ->where('items.0.engine_post_id', 'M1')
            ->where('items.0.share_url', $url)
            ->where('items.0.resource.files.0.href', 'https://example.com/sheet.pdf')
            ->has('items.0.resource_pages', 2)
            ->where('items.0.messages.email_subject.text', 'Your Revenue Equation Sheet'));
});

it('opens a link to an approved post on the Scheduled tab, and ignores a link someone cannot see', function () {
    $this->item->forceFill(['review_status' => ReviewStatus::Scheduled])->save();

    $this->actingAs($this->teammate)->get($this->item->shareUrl())
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'scheduled')->where('focus', $this->item->id));

    $this->actingAs($this->outsider)->get($this->item->shareUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('focus', null)->has('items', 0));
});

it('shows the asset on a public preview link, and nothing for a wrong token', function () {
    $url = $this->item->previewUrl();
    expect($url)->toContain('/previewlink/');
    $updatedAt = $this->item->fresh()->updated_at;

    $this->get($url)
        ->assertOk()
        ->assertSee('The Revenue Equation Sheet')
        ->assertSee('One page with the four numbers.')
        ->assertSee('https://example.com/sheet.pdf', false)
        ->assertSee('r02.jpg', false)
        ->assertSee('noindex', false);

    expect($this->item->fresh()->previewUrl())->toBe($url)
        ->and($this->item->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();

    $this->get('/previewlink/'.str_repeat('x', 40))->assertNotFound();
});

it('lets the reviewer edit a follow-up message, keeps it through a re-push, and restores it', function () {
    $this->actingAs($this->teammate)
        ->postJson(route('tantra.review.items.message', [$this->item, 'sms']), ['text' => 'Hi {first_name}, short one: https://example.com/s'])
        ->assertOk();

    $messages = Messages::for($this->item->refresh());
    expect($messages['sms']['text'])->toBe('Hi {first_name}, short one: https://example.com/s')
        ->and($messages['sms']['edited'])->toBeTrue()
        ->and($messages['whatsapp']['edited'])->toBeFalse();

    ($this->push)(['messages' => ['sms' => 'A newer pushed SMS']]);
    expect(Messages::for($this->item->refresh())['sms']['text'])->toBe('Hi {first_name}, short one: https://example.com/s');

    $this->actingAs($this->teammate)
        ->postJson(route('tantra.review.items.message', [$this->item, 'sms']), ['text' => null])
        ->assertOk();
    expect(Messages::for($this->item->refresh())['sms']['text'])->toBe('A newer pushed SMS');
});

it('refuses message edits from anyone but the reviewer, with dashes, or for an unknown channel', function () {
    $this->actingAs($this->owner)
        ->postJson(route('tantra.review.items.message', [$this->item, 'sms']), ['text' => 'Owner edit'])
        ->assertUnprocessable();

    expect(fn () => ReviewActions::editMessage($this->teammate, $this->item, 'sms', "A dash \u{2014} here"))->toThrow(ValidationException::class);
    expect(fn () => ReviewActions::editMessage($this->teammate, $this->item, 'fax', 'Hi'))->toThrow(ValidationException::class);
    expect(fn () => ReviewActions::editMessage($this->teammate, $this->item, 'linkedin_connect', str_repeat('a', 301)))->toThrow(ValidationException::class);
});

it('builds plain defaults from the resource when no messages were pushed', function () {
    $item = $this->item;
    $item->forceFill(['payload' => [...$item->payload, 'messages' => null]])->save();

    $messages = Messages::for($item->refresh());
    expect($messages)->toHaveKeys(['linkedin_dm', 'linkedin_connect', 'email_subject', 'email_body', 'whatsapp', 'sms'])
        ->and($messages['email_subject']['text'])->toBe("Here's The Revenue Equation Sheet")
        ->and($messages['sms']['text'])->toContain('https://example.com/sheet/');

    $plain = ReviewItem::query()->where('engine_post_id', 'A1')->sole();
    expect(Messages::for($plain))->toBe([]);
});

it('gives follow-up workflows the edited message, with the name filled in', function () {
    ReviewActions::editMessage($this->teammate, $this->item, 'whatsapp', 'Hi {first_name}, edited WhatsApp.');

    $this->withHeaders($this->headers)
        ->getJson(route('tantra.api.messages.show', ['engineBatchId' => '4', 'enginePostId' => 'M1', 'first_name' => 'Sam']))
        ->assertOk()
        ->assertJsonPath('data.messages.whatsapp.text', 'Hi {first_name}, edited WhatsApp.')
        ->assertJsonPath('data.messages.whatsapp.rendered', 'Hi Sam, edited WhatsApp.')
        ->assertJsonPath('data.messages.whatsapp.edited', true)
        ->assertJsonPath('data.resource.name', 'The Revenue Equation Sheet')
        ->assertJsonPath('data.preview_url', $this->item->refresh()->previewUrl());

    $this->withHeaders($this->headers)
        ->getJson(route('tantra.api.messages.show', ['engineBatchId' => '4', 'enginePostId' => 'NOPE']))
        ->assertNotFound();
});
