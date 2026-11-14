<?php

declare(strict_types=1);

namespace App\Tantra\Roadmap;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * tantra: one card on the public roadmap. The requester's email and IP hash
 * are private: publicArray() never carries them.
 *
 * @property string $id
 * @property string $title
 * @property string|null $description
 * @property Column $column
 * @property string $product
 * @property string|null $category
 * @property int $position
 * @property string $source
 * @property string|null $requester_name
 * @property string|null $requester_email
 * @property CarbonInterface|null $shipped_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class RoadmapItem extends Model
{
    use HasUlids;

    protected $table = 'tantra_roadmap_items';

    protected $fillable = [
        'title', 'description', 'column', 'product', 'category', 'position', 'source',
        'requester_name', 'requester_email', 'requester_ip_hash', 'shipped_at',
    ];

    protected $hidden = ['requester_email', 'requester_ip_hash'];

    protected $attributes = [
        'column' => Column::DEFAULT->value,
        'product' => Roadmap::DEFAULT_PRODUCT,
        'position' => 0,
        'source' => 'team',
    ];

    protected function casts(): array
    {
        return [
            'column' => Column::class,
            'position' => 'integer',
            'shipped_at' => 'datetime',
        ];
    }

    /**
     * What anyone may read: the page, the JSON, the feed and agents.
     *
     * @return array<string, mixed>
     */
    public function publicArray(): array
    {
        [$summary, $detail] = $this->split();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $summary,
            'detail' => $detail,
            'column' => $this->column->value,
            'column_label' => $this->column->label(),
            'product' => $this->product,
            'product_label' => Roadmap::PRODUCTS[$this->product] ?? $this->product,
            'category' => $this->category,
            'position' => $this->position,
            'source' => $this->source,
            // First name only: the full name and the email stay private.
            'requested_by' => $this->source === 'request' && filled($this->requester_name)
                ? (string) str($this->requester_name)->trim()->before(' ')
                : null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            'shipped_at' => $this->shipped_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * What the owner and webhooks see: the public card plus who asked.
     *
     * @return array<string, mixed>
     */
    public function privateArray(): array
    {
        return $this->publicArray() + [
            'description' => $this->description,
            'requester' => $this->source === 'request'
                ? ['name' => $this->requester_name, 'email' => $this->requester_email]
                : null,
        ];
    }

    /**
     * The first paragraph is the card's line; the rest opens on expand.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function split(): array
    {
        $text = trim((string) $this->description);
        if ($text === '') {
            return [null, null];
        }

        $parts = preg_split("/\R\s*\R/u", $text, 2) ?: [$text];

        return [trim($parts[0]), isset($parts[1]) ? trim($parts[1]) : null];
    }
}
