<?php

declare(strict_types=1);

return [
    'documents' => [
        'max_file_size' => 10485760, // 10 MB, bytes

        /**
         * Allowed MIME types for document attachments.
         * Copied verbatim from AttachmentService::ALLOWED_MIME_TYPES (Phase-1 MED-1: no regression).
         *
         * @var array<string>
         */
        'allowed_mime_types' => [
            // Images
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            // Documents
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            // Text
            'text/plain',
            'text/csv',
        ],

        /**
         * Allowed file extensions for document attachments.
         * Copied verbatim from AttachmentService::getAllowedExtensions() (Phase-1 MED-1: no regression).
         *
         * @var array<string>
         */
        'allowed_extensions' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'pdf', 'doc', 'docx', 'xls', 'xlsx',
            'txt', 'csv',
        ],
    ],
];
