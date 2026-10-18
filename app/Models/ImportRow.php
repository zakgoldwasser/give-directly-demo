<?php

namespace App\Models;

use Database\Factories\ImportRowFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    /** @use HasFactory<ImportRowFactory> */
    use HasFactory;

    use HasUuids;

    public const STATUS_STAGED = 'staged';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'import_batch_id',
        'row_number',
        'raw_data',
        'normalized_data',
        'corrected_data',
        'status',
        'validation_issues',
        'row_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'normalized_data' => 'array',
            'corrected_data' => 'array',
            'validation_issues' => 'array',
            'row_number' => 'integer',
            'row_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
