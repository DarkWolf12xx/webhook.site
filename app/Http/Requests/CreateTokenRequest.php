<?php

namespace App\Http\Requests;

class CreateTokenRequest extends Request
{
    /**
     * Largest custom response body (characters). Requests are capped at 1 MiB by
     * nginx anyway; this keeps the limit when the app is served differently.
     */
    public const MAX_CONTENT_LENGTH = 1048576;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'default_content' => ['string', 'max:'.self::MAX_CONTENT_LENGTH],
            // Sent verbatim as the Content-Type header: printable ASCII only (no CR/LF)
            'default_content_type' => ['string', 'max:255', 'regex:/\A[\x20-\x7E]+\z/'],
            // Any status the HTTP layer can send; anything else made every request
            // to the URL fail with HTTP 500
            'default_status' => ['integer', 'between:100,599'],
            'timeout' => ['integer', 'min:0', 'max:10'],
        ];
    }
}
