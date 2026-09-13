<?php

namespace App\Http\Requests;

use App\Models\EmployeeDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageDocuments', $this->route('employee'));
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(EmployeeDocument::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:issued_at'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],

            /*
             * Which scan this upload is answering, for the accuracy figures.
             * Declared here so it is a validated integer rather than raw
             * input — it is not part of the document, and storeDocument()
             * ignores it; the controller uses it to complete a measurement.
             */
            'scan_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'The document must not exceed 10 MB.',
            'file.mimes' => 'Allowed formats: PDF, JPG, PNG, DOC, DOCX.',
        ];
    }
}
