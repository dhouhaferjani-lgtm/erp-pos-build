import { useCallback, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  Upload,
  File,
  FileText,
  Image,
  Trash2,
  Download,
  X,
  AlertCircle,
  Loader2
} from 'lucide-react'
import {
  useAttachments,
  useUploadAttachment,
  useDeleteAttachment,
  useAttachmentConfig,
  getAttachmentDownloadUrl,
  type DocumentAttachment
} from '../hooks/useAttachments'
import { useAuthStore } from '../../../stores/authStore'

interface DocumentAttachmentsProps {
  documentId: string
  readOnly?: boolean
}

export function DocumentAttachments({ documentId, readOnly = false }: DocumentAttachmentsProps) {
  const { t } = useTranslation(['common', 'documents'])
  const { data: attachments, isLoading } = useAttachments(documentId)
  const { data: config } = useAttachmentConfig()
  const uploadMutation = useUploadAttachment(documentId)
  const deleteMutation = useDeleteAttachment(documentId)
  const token = useAuthStore((state) => state.token)

  const [dragActive, setDragActive] = useState(false)
  const [uploadError, setUploadError] = useState<string | null>(null)
  const fileInputRef = useRef<HTMLInputElement>(null)

  const handleDrag = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    if (e.type === 'dragenter' || e.type === 'dragover') {
      setDragActive(true)
    } else if (e.type === 'dragleave') {
      setDragActive(false)
    }
  }, [])

  const validateFile = useCallback((file: File): string | null => {
    if (!config) return null

    if (file.size > config.max_file_size) {
      return t('documents:attachments.errors.fileTooLarge', {
        maxSize: config.max_file_size_mb
      })
    }

    const extension = file.name.split('.').pop()?.toLowerCase()
    if (extension && !config.allowed_extensions.includes(extension)) {
      return t('documents:attachments.errors.invalidType', {
        types: config.allowed_extensions.join(', ')
      })
    }

    return null
  }, [config, t])

  const handleFiles = useCallback(async (files: FileList | null) => {
    if (!files || files.length === 0) return

    setUploadError(null)

    for (let i = 0; i < files.length; i++) {
      const file = files[i]
      const error = validateFile(file)

      if (error) {
        setUploadError(error)
        continue
      }

      try {
        await uploadMutation.mutateAsync({ file })
      } catch (err) {
        setUploadError(err instanceof Error ? err.message : t('common:error'))
      }
    }
  }, [uploadMutation, validateFile, t])

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setDragActive(false)

    if (readOnly) return
    void handleFiles(e.dataTransfer.files)
  }, [handleFiles, readOnly])

  const handleFileSelect = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    void handleFiles(e.target.files)
    // Reset input value so the same file can be selected again
    if (fileInputRef.current) {
      fileInputRef.current.value = ''
    }
  }, [handleFiles])

  const handleDelete = useCallback(async (attachmentId: string) => {
    try {
      await deleteMutation.mutateAsync(attachmentId)
    } catch (err) {
      setUploadError(err instanceof Error ? err.message : t('common:error'))
    }
  }, [deleteMutation, t])

  const handleDownload = useCallback((attachment: DocumentAttachment) => {
    const url = getAttachmentDownloadUrl(documentId, attachment.id)
    // Create a link with auth header
    const link = document.createElement('a')
    link.href = url
    if (token) {
      // For authenticated downloads, we need to fetch with the token
      fetch(url, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      })
        .then(response => response.blob())
        .then(blob => {
          const blobUrl = window.URL.createObjectURL(blob)
          link.href = blobUrl
          link.download = attachment.original_filename
          document.body.appendChild(link)
          link.click()
          document.body.removeChild(link)
          window.URL.revokeObjectURL(blobUrl)
        })
        .catch(() => {
          setUploadError(t('documents:attachments.errors.downloadFailed'))
        })
    }
  }, [documentId, token, t])

  const getFileIcon = (attachment: DocumentAttachment) => {
    if (attachment.is_image) {
      return <Image className="h-5 w-5 text-blue-500" />
    }
    if (attachment.is_pdf) {
      return <FileText className="h-5 w-5 text-red-500" />
    }
    return <File className="h-5 w-5 text-gray-500" />
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-8">
        <Loader2 className="h-6 w-6 animate-spin text-gray-400" />
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {/* Upload Area */}
      {!readOnly && (
        <div
          onDragEnter={handleDrag}
          onDragLeave={handleDrag}
          onDragOver={handleDrag}
          onDrop={handleDrop}
          className={`
            relative border-2 border-dashed rounded-lg p-6 text-center transition-colors
            ${dragActive
              ? 'border-primary-500 bg-primary-50'
              : 'border-gray-300 hover:border-gray-400'
            }
          `}
        >
          <input
            ref={fileInputRef}
            type="file"
            multiple
            onChange={handleFileSelect}
            className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
            accept={config?.allowed_extensions.map(ext => `.${ext}`).join(',')}
          />
          <Upload className="mx-auto h-8 w-8 text-gray-400 mb-2" />
          <p className="text-sm text-gray-600">
            {t('documents:attachments.dropzone')}
          </p>
          <p className="text-xs text-gray-500 mt-1">
            {t('documents:attachments.allowedTypes', {
              types: config?.allowed_extensions.join(', ') ?? '',
              maxSize: config?.max_file_size_mb ?? 10
            })}
          </p>

          {uploadMutation.isPending && (
            <div className="absolute inset-0 bg-white/80 flex items-center justify-center rounded-lg">
              <Loader2 className="h-6 w-6 animate-spin text-primary-500" />
              <span className="ms-2 text-sm text-gray-600">
                {t('documents:attachments.uploading')}
              </span>
            </div>
          )}
        </div>
      )}

      {/* Error Message */}
      {uploadError && (
        <div className="flex items-center gap-2 p-3 bg-red-50 border border-red-200 rounded-lg text-red-700">
          <AlertCircle className="h-4 w-4 flex-shrink-0" />
          <span className="text-sm">{uploadError}</span>
          <button
            onClick={() => setUploadError(null)}
            className="ms-auto p-1 hover:bg-red-100 rounded"
          >
            <X className="h-4 w-4" />
          </button>
        </div>
      )}

      {/* Attachments List */}
      {attachments && attachments.length > 0 ? (
        <div className="border rounded-lg divide-y">
          {attachments.map((attachment) => (
            <div
              key={attachment.id}
              className="flex items-center gap-3 p-3 hover:bg-gray-50"
            >
              {getFileIcon(attachment)}
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-gray-900 truncate">
                  {attachment.original_filename}
                </p>
                <p className="text-xs text-gray-500">
                  {attachment.formatted_file_size}
                  {attachment.description && ` - ${attachment.description}`}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <span className="text-xs text-gray-400">
                  {attachment.uploaded_by.name}
                </span>
                <button
                  onClick={() => handleDownload(attachment)}
                  className="p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded"
                  title={t('common:download')}
                >
                  <Download className="h-4 w-4" />
                </button>
                {!readOnly && (
                  <button
                    onClick={() => void handleDelete(attachment.id)}
                    disabled={deleteMutation.isPending}
                    className="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded disabled:opacity-50"
                    title={t('common:delete')}
                  >
                    {deleteMutation.isPending ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                      <Trash2 className="h-4 w-4" />
                    )}
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="text-center py-8 text-gray-500 text-sm">
          {t('documents:attachments.noAttachments')}
        </div>
      )}
    </div>
  )
}
