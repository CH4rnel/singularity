<?php

namespace App\Models;

use Database\Factories\CrmContactLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmContactLink extends Model
{
    /** @use HasFactory<CrmContactLinkFactory> */
    use HasFactory;

    protected $fillable = ['label', 'kind', 'url'];

    /** @return BelongsTo<CrmContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'crm_contact_id');
    }
}
