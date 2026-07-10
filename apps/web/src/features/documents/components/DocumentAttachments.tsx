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
import { colorClasses } from '@/lib/designTokens'

interface DocumentAttachmentsProps {
  documentId: string
  readOnly?: boolean
  /** Optional attachment role forwarded to the backend on upload (e.g. "SOURCE_DOCUMENT"). */
  defaultRole?: string
}

export function DocumentAttachments({ documentId, readOnly = false, defaultRole }: DocumentAttachmentsProps) {
  const { t } = useTranslation(['common', 'documents'])
  const { data: attachments, isLoading } = useAttachments(documentId)
  const { data: config } = useAttachmentConfig()
  const uploadMutation = useUploadAttachment(documentId, defaultRole)
  const deleteMutation = useDeleteAttachment(documentId)

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
    // For authenticated downloads, use fetch with credentials (session cookie)
    fetch(url, {
      credentials: 'include', // Include session cookie for authentication
    })
      .then(response => response.blob())
      .then(blob => {
        const blobUrl = window.URL.createObjectURL(blob)
        const link = document.createElement('a')
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
  }, [documentId, t])

  const getFileIcon = (attachment: DocumentAttachment) => {
    if (attachment.is_image) {
      return <Image className={`h-5 w-5 ${colorClasses.textBlue500}`} />
    }
    if (attachment.is_pdf) {
      return <FileText className={`h-5 w-5 ${colorClasses.textRed500}`} />
    }
    return <File className={`h-5 w-5 ${colorClasses.textGray500}`} />
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-8">
        <Loader2 className={`h-6 w-6 animate-spin ${colorClasses.textGray400}`} />
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
              : `${colorClasses.borderGray300} ${colorClasses.hoverBorderGray400}`
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
          <Upload className={`mx-auto h-8 w-8 ${colorClasses.textGray400} mb-2`} />
          <p className={`text-sm ${colorClasses.textGray600}`}>
            {t('documents:attachments.dropzone')}
          </p>
          <p className={`text-xs ${colorClasses.textGray500} mt-1`}>
            {t('documents:attachments.allowedTypes', {
              types: config?.allowed_extensions.join(', ') ?? '',
              maxSize: config?.max_file_size_mb ?? 10
            })}
          </p>

          {uploadMutation.isPending && (
            <div className="absolute inset-0 bg-white/80 flex items-center justify-center rounded-lg">
              <Loader2 className="h-6 w-6 animate-spin text-primary-500" />
              <span className={`ms-2 text-sm ${colorClasses.textGray600}`}>
                {t('documents:attachments.uploading')}
              </span>
            </div>
          )}
        </div>
      )}

      {/* Error Message */}
      {uploadError && (
        <div className={`flex items-center gap-2 p-3 ${colorClasses.bgRed50} border ${colorClasses.borderRed200} rounded-lg ${colorClasses.textRed700}`}>
          <AlertCircle className="h-4 w-4 flex-shrink-0" />
          <span className="text-sm">{uploadError}</span>
          <button
            onClick={() => { setUploadError(null); }}
            className={`ms-auto p-1 ${colorClasses.hoverBgRed100} rounded`}
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
              className={`flex items-center gap-3 p-3 ${colorClasses.hoverBgGray50}`}
            >
              {getFileIcon(attachment)}
              <div className="flex-1 min-w-0">
                <p className={`text-sm font-medium ${colorClasses.textGray900} truncate`}>
                  {attachment.original_filename}
                </p>
                <p className={`text-xs ${colorClasses.textGray500}`}>
                  {attachment.formatted_file_size}
                  {attachment.description && ` - ${attachment.description}`}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <span className={`text-xs ${colorClasses.textGray400}`}>
                  {attachment.uploaded_by.name}
                </span>
                <button
                  onClick={() => { handleDownload(attachment); }}
                  className={`p-1.5 ${colorClasses.textGray400} ${colorClasses.hoverTextGray600} ${colorClasses.hoverBgGray100} rounded`}
                  title={t('common:download')}
                >
                  <Download className="h-4 w-4" />
                </button>
                {!readOnly && (
                  <button
                    onClick={() => void handleDelete(attachment.id)}
                    disabled={deleteMutation.isPending}
                    className={`p-1.5 ${colorClasses.textGray400} ${colorClasses.hoverTextRed600} ${colorClasses.hoverBgRed50} rounded disabled:opacity-50`}
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
        <div className={`text-center py-8 ${colorClasses.textGray500} text-sm`}>
          {t('documents:attachments.noAttachments')}
        </div>
      )}
    </div>
  )
}
