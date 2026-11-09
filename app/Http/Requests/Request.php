<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

abstract class Request extends FormRequest
{
    /**
     * Respond to validation failures with a JSON map of field => messages and
     * HTTP 422, the format this API has always returned (and which the
     * frontend parses), instead of the framework's redirect/new JSON shape.
     *
     * @param Validator $validator
     * @return void
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(new JsonResponse(
            $validator->errors()->getMessages(),
            JsonResponse::HTTP_UNPROCESSABLE_ENTITY
        ));
    }
}
