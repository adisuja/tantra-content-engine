<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Tantra\Review\Actions\ImportBatch;
use App\Tantra\Review\Models\ReviewItem;
use App\Tantra\Review\Models\WorkspaceSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake(config('filesystems.default'));
    $result = createApiTestToken();
    $this->adi = $result['user'];

    // Ben has not connected LinkedIn: his post has no drafts yet, its video waits as a held upload.
    $this->ben = User::factory()->create(['account_id' => $this->adi->account_id, 'name' => 'Ben Basil Tomy', 'password' => null]);
    $this->ws = Workspace::factory()->create(['account_id' => $this->adi->account_id, 'user_id' => $this->adi->id]);
    WorkspaceSettings::query()->create(['workspace_id' => $this->ws->id, 'brand_profile_key' => 'ben', 'review_owner_user_id' => $this->ben->id]);

    ImportBatch::execute($this->adi, ['engine_batch_id' => '4', 'posts' => [[
        'engine_post_id' => 'EA1', 'profile' => 'ben', 'platform' => 'linkedin', 'date' => '2026-11-24', 'time' => '08:30', 'tz' => 'UTC', 'text' => 'Four numbers.',
    ]]]);
    $this->item = ReviewItem::query()->where('engine_post_id', 'EA1')->sole();

    $token = (string) str()->uuid();
    $media = Media::factory()->temporaryUpload($this->ws)->create([
        'collection' => 'tantra_held', 'type' => 'video', 'path' => 'medias/ea1.mp4',
        'original_filename' => 'ea1.mp4', 'mime_type' => 'video/mp4', 'upload_token' => $token,
    ]);
    $this->item->forceFill(['media_manifest' => ['type' => 'video', 'previews' => ['tantra/previews/EA1/poster.jpg'], 'held_tokens' => [$token]]])->save();
    $this->videoUrl = $media->url;
});

it('plays a held video in the preview before the drafts exist', function () {
    $this->actingAs($this->adi)->get(route('tantra.review.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.engine_post_id', 'EA1')
            ->where('items.0.media.type', 'video')
            ->where('items.0.media.video', $this->videoUrl));
});

it('shows the author as LinkedIn has them once their account is read', function () {
    config(['tantra.unipile.dsn' => 'https://unipile.test/v2', 'tantra.unipile.key' => 'k']);
    Http::fake([
        'unipile.test/v2/accounts*' => Http::response(['items' => [['id' => 'acc_ben', 'name' => 'Ben Basil Tomy', 'provider' => 'LINKEDIN', 'status' => 'OK']]]),
        'unipile.test/v2/acc_ben/users/me' => Http::response([
            'id' => 'ACoAABen', 'display_name' => 'Ben Basil Tomy', 'description' => 'Backend Engineer | Node.js',
            'public_picture_url_large' => 'https://media.licdn.test/ben.jpg?e=1&t=x', 'profile_url' => 'https://www.linkedin.com/in/ben',
        ]),
        'media.licdn.test/*' => Http::response(base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->artisan('tantra:linkedin-sync')->assertSuccessful();

    expect($this->ben->refresh()->has_photo)->toBeTrue();
    $this->actingAs($this->adi)->get(route('tantra.review.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.profile.name', 'Ben Basil Tomy')
            ->where('items.0.profile.headline', 'Backend Engineer | Node.js')
            ->where('items.0.profile.avatar', Storage::url('tantra/linkedin/'.$this->ben->id.'.jpg')));
});
