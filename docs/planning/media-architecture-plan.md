# Otospex Media Architecture Plan

> **Status:** Planning Phase (Not Yet Implemented)  
> **Created:** December 2024  
> **Target Implementation:** After core module stabilization  

---

## 1. Overview

This document outlines the media management architecture for Otospex (automotive ERP) and IziPOS (generic ERP/POS). The design supports:

- Multi-tenant file storage with proper isolation
- Product images (single and gallery)
- Technical documents (PDFs, spec sheets)
- Vehicle reception/work order photos
- Future: Video embeds, 3D views
- Bulk import from ZIP archives and URL references
- External media references (TecDoc integration)

---

## 2. Storage Strategy

### 2.1 Provider Progression

| Phase | Provider | Use Case |
|-------|----------|----------|
| Development | MinIO (local) | Local development, testing |
| Early Production | Cloudflare R2 | Cost-effective, S3-compatible |
| Scale (if needed) | AWS S3 | Enterprise requirements |

### 2.2 Multi-Tenant Isolation

**Chosen approach:** Prefix-based with signed URLs

```
otospex-media/                          # Single bucket
├── {tenant-uuid}/                      # Tenant prefix
│   ├── products/
│   │   └── {product-uuid}/
│   │       ├── original/
│   │       │   └── image-001.jpg       # Original upload
│   │       ├── thumbnails/
│   │       │   ├── 64x64.webp          # Icon size
│   │       │   ├── 256x256.webp        # List/grid view
│   │       │   └── 800x800.webp        # Detail view
│   │       └── watermarked/
│   │           └── marketplace-800.webp
│   ├── vehicles/
│   │   └── {vehicle-uuid}/
│   │       ├── reception/              # Check-in photos
│   │       ├── damage-reports/
│   │       └── completion/             # Delivery photos
│   ├── work-orders/
│   │   └── {work-order-uuid}/
│   │       ├── before/
│   │       ├── during/
│   │       ├── after/
│   │       └── customer-approval/
│   └── documents/
│       ├── invoices/
│       └── technical/
```

**Why this approach:**

- Single bucket simplifies management, backup, and migration
- Application already enforces tenant isolation via PostgreSQL schemas
- Signed URLs (pre-signed, time-limited) ensure secure access
- Mirrors the existing tenant isolation pattern
- Future option: Bucket-per-tenant for enterprise clients requiring true isolation

---

## 3. Database Schema

### 3.1 Media Files Table

Stores the actual file metadata. One file can be attached to multiple entities.

```sql
CREATE TABLE media_files (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    -- Storage info
    disk VARCHAR(50) NOT NULL DEFAULT 'minio',  -- 'local', 'minio', 's3', 'r2'
    path VARCHAR(500) NOT NULL,                  -- Relative path within tenant prefix
    filename VARCHAR(255) NOT NULL,              -- Original filename
    
    -- File info
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT NOT NULL,
    checksum_sha256 VARCHAR(64),                 -- Integrity verification
    
    -- Metadata
    metadata JSONB DEFAULT '{}',                 -- Dimensions, EXIF, dominant color, etc.
    variants JSONB DEFAULT '{}',                 -- Generated thumbnails/versions
    
    -- Source tracking
    source_type VARCHAR(50) NOT NULL DEFAULT 'upload',  -- 'upload', 'import', 'capture', 'external'
    source_reference VARCHAR(500),               -- External URL if applicable
    
    -- Audit
    uploaded_by UUID REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    -- Constraints
    CONSTRAINT media_files_path_unique UNIQUE (tenant_id, disk, path)
);

-- RLS Policy
ALTER TABLE media_files ENABLE ROW LEVEL SECURITY;
CREATE POLICY media_files_tenant_isolation ON media_files
    USING (tenant_id = current_setting('app.current_tenant_id')::uuid);

-- Indexes
CREATE INDEX idx_media_files_tenant ON media_files(tenant_id);
CREATE INDEX idx_media_files_checksum ON media_files(checksum_sha256);
```

**Variants JSONB structure:**

```json
{
  "thumb_64": {
    "path": "thumbnails/64x64.webp",
    "size": 2048,
    "width": 64,
    "height": 64
  },
  "thumb_256": {
    "path": "thumbnails/256x256.webp",
    "size": 12000,
    "width": 256,
    "height": 256
  },
  "display_800": {
    "path": "thumbnails/800x800.webp",
    "size": 45000,
    "width": 800,
    "height": 800
  },
  "watermarked": {
    "path": "watermarked/marketplace-800.webp",
    "size": 48000,
    "width": 800,
    "height": 800
  }
}
```

### 3.2 Media Attachments Table (Polymorphic Pivot)

Links media files to any entity in the system.

```sql
CREATE TABLE media_attachments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    media_file_id UUID NOT NULL REFERENCES media_files(id) ON DELETE CASCADE,
    
    -- Polymorphic relationship
    attachable_type VARCHAR(100) NOT NULL,       -- 'Product', 'Vehicle', 'WorkOrder', etc.
    attachable_id UUID NOT NULL,
    
    -- Organization
    collection VARCHAR(50) NOT NULL DEFAULT 'gallery',  -- 'featured', 'gallery', 'documents', etc.
    position INTEGER NOT NULL DEFAULT 0,          -- Ordering within collection
    
    -- Context-specific metadata
    metadata JSONB DEFAULT '{}',                  -- Alt text, caption, visibility flags
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    -- Constraints
    CONSTRAINT media_attachments_unique UNIQUE (media_file_id, attachable_type, attachable_id, collection)
);

-- Indexes for polymorphic lookups
CREATE INDEX idx_media_attachments_attachable 
    ON media_attachments(attachable_type, attachable_id);
CREATE INDEX idx_media_attachments_collection 
    ON media_attachments(attachable_type, attachable_id, collection);
CREATE INDEX idx_media_attachments_position 
    ON media_attachments(attachable_type, attachable_id, collection, position);
```

### 3.3 External Media References Table

For TecDoc and other external image sources that shouldn't be duplicated.

```sql
CREATE TABLE external_media_references (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    -- Polymorphic relationship
    attachable_type VARCHAR(100) NOT NULL,
    attachable_id UUID NOT NULL,
    
    -- Organization
    collection VARCHAR(50) NOT NULL DEFAULT 'gallery',
    position INTEGER NOT NULL DEFAULT 0,
    
    -- External source
    provider VARCHAR(50) NOT NULL,               -- 'tecdoc', 'manufacturer', 'supplier'
    external_url VARCHAR(1000) NOT NULL,
    
    -- Provider-specific metadata
    metadata JSONB DEFAULT '{}',                 -- Provider IDs, last verified, etc.
    
    -- Optional local cache
    cached_locally BOOLEAN DEFAULT FALSE,
    local_media_file_id UUID REFERENCES media_files(id),
    cache_expires_at TIMESTAMPTZ,
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- RLS Policy
ALTER TABLE external_media_references ENABLE ROW LEVEL SECURITY;
CREATE POLICY external_media_refs_tenant_isolation ON external_media_references
    USING (tenant_id = current_setting('app.current_tenant_id')::uuid);

-- Indexes
CREATE INDEX idx_external_media_refs_attachable 
    ON external_media_references(attachable_type, attachable_id);
CREATE INDEX idx_external_media_refs_provider 
    ON external_media_references(provider);
```

---

## 4. Collections by Vertical

### 4.1 Standard Collections

| Collection | Description | Used By |
|------------|-------------|---------|
| `featured` | Primary display image | All entities |
| `gallery` | Additional images | Products, Vehicles |
| `documents` | PDFs, technical docs | Products, Work Orders |
| `technical_diagrams` | Fitment, wiring diagrams | Automotive parts |

### 4.2 Automotive-Specific Collections

| Collection | Description | Entity |
|------------|-------------|--------|
| `reception` | Vehicle check-in photos | Vehicle, Work Order |
| `damage_report` | Pre-existing damage documentation | Vehicle |
| `during_work` | Work in progress photos | Work Order |
| `completion` | Finished work documentation | Work Order |
| `customer_approval` | Photos requiring customer sign-off | Work Order |
| `oem_packaging` | Original packaging (authenticity) | Product |

### 4.3 Other Vertical Collections

| Vertical | Collections |
|----------|-------------|
| F&B | `featured`, `menu_board`, `allergen_docs` |
| Para-pharmacy | `featured`, `gallery`, `pil_documents`, `batch_specific` |
| Retail | `featured`, `gallery` |

---

## 5. Bulk Import Workflow

### 5.1 Supported Import Methods

#### Method A: ZIP + Spreadsheet (Primary)

User provides a CSV/Excel and a ZIP file with images.

**Matching rules (configurable):**

```
images.zip/
├── ABC123.jpg          → SKU "ABC123" featured image
├── ABC123_2.jpg        → SKU "ABC123" gallery image
├── ABC123_back.jpg     → SKU "ABC123" gallery image
├── DEF456.jpg          → SKU "DEF456" featured image
└── DEF456.png          → (alternative extension supported)
```

**CSV structure:**

```csv
sku,name,price,image
ABC123,Brake Pad Set,45.99,ABC123.jpg
DEF456,Oil Filter,12.99,DEF456.jpg
```

#### Method B: URL References in Spreadsheet

```csv
sku,name,image_url,image_url_2
ABC123,Brake Pad,https://supplier.com/abc123.jpg,https://supplier.com/abc123_alt.jpg
```

System fetches images asynchronously during import.

#### Method C: Pre-uploaded to Staging

1. User uploads images to staging area
2. User uploads CSV with filenames
3. System matches and moves to permanent storage

#### Method D: External References (TecDoc)

```csv
sku,tecdoc_article_id
ABC123,12345678
```

System creates `external_media_references` pointing to TecDoc CDN.

### 5.2 Import Configuration Schema

```json
{
  "image_matching": {
    "primary_field": "sku",
    "fallback_fields": ["barcode", "reference"],
    "pattern": "{field}.{ext}",
    "gallery_pattern": "{field}_{n}",
    "gallery_suffixes": ["_2", "_3", "_back", "_front", "_detail"],
    "case_sensitive": false,
    "extensions": ["jpg", "jpeg", "png", "webp", "gif"]
  },
  "processing": {
    "generate_thumbnails": true,
    "thumbnail_sizes": [64, 256, 800],
    "convert_to_webp": true,
    "webp_quality": 85,
    "max_dimension": 2000,
    "strip_exif": false
  },
  "error_handling": {
    "missing_image": "skip",
    "invalid_format": "skip",
    "corrupt_file": "skip",
    "duplicate": "replace",
    "oversized": "resize"
  },
  "limits": {
    "max_file_size_mb": 10,
    "max_zip_size_mb": 500,
    "max_files_per_import": 5000
  }
}
```

---

## 6. Image Processing Pipeline

### 6.1 Processing Flow

```
Upload/Import
     │
     ▼
┌─────────────────┐
│   Validation    │──► Check: mime type, size, dimensions, malware scan
└─────────────────┘
     │
     ▼
┌─────────────────┐
│ Store Original  │──► Save to: {tenant}/products/{uuid}/original/
└─────────────────┘
     │
     ▼
┌─────────────────┐
│   Queue Jobs    │──► Async processing (non-blocking upload)
└─────────────────┘
     │
     ├──► GenerateThumbnailJob (64x64, 256x256, 800x800)
     │
     ├──► ConvertToWebpJob (if source is jpg/png)
     │
     ├──► ExtractMetadataJob (dimensions, EXIF, dominant color)
     │
     └──► [On-demand] GenerateWatermarkJob (for marketplace export)
```

### 6.2 Recommended Libraries

| Library | Use Case | Notes |
|---------|----------|-------|
| **Intervention Image** | Primary choice | Laravel-native, handles WebP, good performance |
| Spatie Media Library | Alternative | More features, but opinionated structure |
| imgproxy | Scale solution | Microservice, better for high volume |

**Start with Intervention Image** - it's sufficient for initial needs and well-integrated with Laravel.

### 6.3 Thumbnail Specifications

| Size | Use Case | Format | Quality |
|------|----------|--------|---------|
| 64×64 | Icons, tiny previews | WebP | 80 |
| 256×256 | Grid views, lists | WebP | 85 |
| 800×800 | Detail pages, lightbox | WebP | 90 |
| Original | Backup, print, zoom | Preserved | - |

---

## 7. API Endpoints (Future)

### 7.1 Media Management

```
POST   /api/media/upload                    # Single file upload
POST   /api/media/upload-batch              # Multiple files (multipart)
POST   /api/media/upload-from-url           # Fetch from external URL
GET    /api/media/{uuid}                    # Get media file details
DELETE /api/media/{uuid}                    # Delete file + all variants

# Serving
GET    /api/media/{uuid}/url                # Get signed URL (default variant)
GET    /api/media/{uuid}/url?variant=thumb_256  # Specific variant
GET    /api/media/{uuid}/download           # Force download
```

### 7.2 Attachments

```
# Products
GET    /api/products/{uuid}/media                     # List all media
POST   /api/products/{uuid}/media                     # Attach media
PUT    /api/products/{uuid}/media/{media_id}          # Update (position, collection)
DELETE /api/products/{uuid}/media/{media_id}          # Detach (doesn't delete file)
PUT    /api/products/{uuid}/media/reorder             # Bulk reorder

# Same pattern for: /vehicles, /work-orders, etc.
```

### 7.3 Bulk Import

```
POST   /api/imports/{import_id}/media-zip             # Upload ZIP for matching
GET    /api/imports/{import_id}/media-preview         # Preview matches before processing
POST   /api/imports/{import_id}/process-media         # Execute matching + processing
GET    /api/imports/{import_id}/media-status          # Processing progress
GET    /api/imports/{import_id}/media-errors          # Failed items
```

---

## 8. Laravel Implementation Notes

### 8.1 Model Structure

```
App\Modules\Media\
├── Domain\
│   ├── MediaFile.php
│   ├── MediaAttachment.php
│   ├── ExternalMediaReference.php
│   └── Enums\
│       ├── MediaCollection.php
│       ├── MediaDisk.php
│       └── MediaSourceType.php
├── Application\
│   ├── Services\
│   │   ├── MediaUploadService.php
│   │   ├── MediaProcessingService.php
│   │   └── SignedUrlService.php
│   └── Jobs\
│       ├── GenerateThumbnailJob.php
│       ├── ConvertToWebpJob.php
│       ├── ExtractMetadataJob.php
│       └── GenerateWatermarkJob.php
├── Infrastructure\
│   ├── Storage\
│   │   ├── MediaStorageAdapter.php
│   │   └── MinioStorageDriver.php
│   └── Repositories\
│       └── MediaFileRepository.php
└── Presentation\
    ├── Controllers\
    │   ├── MediaController.php
    │   └── MediaAttachmentController.php
    └── Requests\
        ├── UploadMediaRequest.php
        └── AttachMediaRequest.php
```

### 8.2 Trait for Attachable Models

```php
<?php

namespace App\Modules\Media\Domain\Traits;

trait HasMedia
{
    public function mediaAttachments(): MorphMany
    {
        return $this->morphMany(MediaAttachment::class, 'attachable');
    }

    public function externalMedia(): MorphMany
    {
        return $this->morphMany(ExternalMediaReference::class, 'attachable');
    }

    public function getFeaturedImageAttribute(): ?MediaFile
    {
        return $this->mediaAttachments()
            ->where('collection', 'featured')
            ->orderBy('position')
            ->first()
            ?->mediaFile;
    }

    public function getGalleryAttribute(): Collection
    {
        return $this->mediaAttachments()
            ->where('collection', 'gallery')
            ->orderBy('position')
            ->with('mediaFile')
            ->get()
            ->pluck('mediaFile');
    }

    public function attachMedia(MediaFile $file, string $collection = 'gallery', int $position = 0): MediaAttachment
    {
        // Implementation
    }

    public function detachMedia(MediaFile $file, ?string $collection = null): void
    {
        // Implementation
    }
}
```

---

## 9. Implementation Phases

### Phase 1: Foundation (Est. 2-3 days)

- [ ] Database migrations
- [ ] MediaFile, MediaAttachment, ExternalMediaReference models
- [ ] Basic upload endpoint (single file)
- [ ] MinIO filesystem configuration
- [ ] Simple attachment to Product model
- [ ] No processing yet (store originals only)

**Deliverable:** Can upload and attach images to products, view originals.

### Phase 2: Processing (Est. 2-3 days)

- [ ] Queue job for thumbnail generation
- [ ] WebP conversion job
- [ ] Metadata extraction job
- [ ] Variants storage and retrieval
- [ ] Signed URL generation service

**Deliverable:** Automatic thumbnail generation, optimized serving.

### Phase 3: Bulk Import (Est. 3-4 days)

- [ ] ZIP upload and extraction
- [ ] Filename matching logic
- [ ] Import configuration options
- [ ] Progress tracking (via existing import job system)
- [ ] Error reporting and recovery
- [ ] Integration with existing CSV import flow

**Deliverable:** Can import products with images from ZIP + spreadsheet.

### Phase 4: Advanced Features (Future)

- [ ] Watermarking service (for marketplace)
- [ ] External reference caching (TecDoc offline)
- [ ] Mobile capture workflow (React Native)
- [ ] Offline sync for Tauri POS
- [ ] Video embed support (YouTube, Vimeo)
- [ ] Bulk operations UI

---

## 10. Configuration Reference

### 10.1 Environment Variables

```env
# Storage
MEDIA_DISK=minio
MEDIA_BUCKET=otospex-media

# MinIO (Development)
MINIO_ENDPOINT=http://localhost:9000
MINIO_ACCESS_KEY=minioadmin
MINIO_SECRET_KEY=minioadmin
MINIO_REGION=us-east-1

# Cloudflare R2 (Production)
R2_ENDPOINT=https://xxx.r2.cloudflarestorage.com
R2_ACCESS_KEY=xxx
R2_SECRET_KEY=xxx
R2_BUCKET=otospex-media

# Processing
MEDIA_MAX_UPLOAD_SIZE_MB=10
MEDIA_THUMBNAIL_SIZES=64,256,800
MEDIA_CONVERT_TO_WEBP=true
MEDIA_WEBP_QUALITY=85

# Signed URLs
MEDIA_SIGNED_URL_EXPIRY_MINUTES=60
```

### 10.2 Filesystem Config (config/filesystems.php)

```php
'disks' => [
    'minio' => [
        'driver' => 's3',
        'key' => env('MINIO_ACCESS_KEY'),
        'secret' => env('MINIO_SECRET_KEY'),
        'region' => env('MINIO_REGION', 'us-east-1'),
        'bucket' => env('MINIO_BUCKET', 'otospex-media'),
        'url' => env('MINIO_URL'),
        'endpoint' => env('MINIO_ENDPOINT'),
        'use_path_style_endpoint' => true,
        'throw' => true,
    ],
    
    'r2' => [
        'driver' => 's3',
        'key' => env('R2_ACCESS_KEY'),
        'secret' => env('R2_SECRET_KEY'),
        'region' => 'auto',
        'bucket' => env('R2_BUCKET'),
        'endpoint' => env('R2_ENDPOINT'),
        'throw' => true,
    ],
],
```

---

## 11. Security Considerations

### 11.1 Upload Validation

- Validate MIME type from file content, not just extension
- Check magic bytes for image formats
- Limit file size (configurable per tenant/plan)
- Scan for malware (optional, via ClamAV or cloud service)
- Strip potentially dangerous EXIF data (GPS, camera info) if privacy-sensitive

### 11.2 Access Control

- All media URLs are signed with expiration
- Tenant isolation enforced at application level AND storage path
- Audit log for sensitive media access (work order photos, etc.)
- Option to require re-authentication for certain collections

### 11.3 Data Retention

- Original files retained for compliance period
- Deleted media soft-deleted first, hard-deleted after retention period
- Work order photos: Retain for warranty period + buffer

---

## 12. Future Considerations

### 12.1 CDN Integration

When traffic grows, add CDN layer:

```
Client → CDN (Cloudflare) → R2/S3
              ↓
         Cache static variants
```

### 12.2 AI Features (Future Roadmap)

- Auto-tagging images (part type, brand detection)
- Quality assessment (blur detection, lighting)
- Duplicate detection (perceptual hashing)
- OCR for part numbers in images

### 12.3 Mobile-Specific

- Progressive JPEG/WebP for slow connections
- Offline queue for photo uploads
- Background sync when connectivity returns
- Automatic compression based on network type

---

## Appendix A: Migration Files

See `database/migrations/` for implementation:

- `xxxx_xx_xx_create_media_files_table.php`
- `xxxx_xx_xx_create_media_attachments_table.php`
- `xxxx_xx_xx_create_external_media_references_table.php`

---

## Appendix B: Related Documentation

- [Otospex Module Structure](./module-structure.md)
- [Bulk Import System](./bulk-import.md)
- [TecDoc Integration](./tecdoc-integration.md)

---

*This document should be reviewed and updated when implementation begins.*
