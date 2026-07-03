import { apiPost } from '@/lib/api'

export interface UploadedPhoto {
  photoId: string
  filename: string
}

interface UploadUrlResponse {
  photo_id: string
  upload_url: string
}

export const MAX_PHOTO_BYTES = 5_242_880

export async function uploadEnrichmentPhoto(file: File): Promise<UploadedPhoto> {
  if (file.size > MAX_PHOTO_BYTES) {
    throw new Error('Photo exceeds maximum upload size')
  }

  const upload = await apiPost<UploadUrlResponse>('/platform/upload-url', {
    filename: file.name,
    content_type: file.type,
    size_bytes: file.size,
  })

  const response = await fetch(upload.upload_url, {
    method: 'PUT',
    body: file,
    headers: {
      'Content-Type': file.type,
    },
  })

  if (!response.ok) {
    throw new Error('Photo upload failed')
  }

  return {
    photoId: upload.photo_id,
    filename: file.name,
  }
}
