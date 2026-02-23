<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\EmailLog
 */
class EmailLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recipient' => $this->recipient,
            'subject' => $this->subject,
            'body' => $this->body,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'sent_at' => $this->sent_at,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'share_id' => $this->share_id,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
