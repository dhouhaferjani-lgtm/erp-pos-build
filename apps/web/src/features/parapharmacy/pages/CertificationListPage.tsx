import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Plus, Edit, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { fetchCertifications, deleteCertification } from '../api/certificationApi';
import { toast } from 'sonner';
import { OffsetPagination } from '@/components/ui/OffsetPagination';

export function CertificationListPage() {
  const { t } = useTranslation(['common', 'parapharmacy']);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [deleteId, setDeleteId] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['parapharmacy', 'certifications', page],
    queryFn: () => fetchCertifications({ page, per_page: 25 }),
  });

  const deleteMutation = useMutation({
    mutationFn: deleteCertification,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parapharmacy', 'certifications'] });
      toast.success(t('parapharmacy:certificationDeleted'));
      setDeleteId(null);
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:deleteCertificationError');
      toast.error(message);
      setDeleteId(null);
    },
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        {t('common:loading')}
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">{t('parapharmacy:certifications')}</h1>
          <p className="text-muted-foreground">
            {t('parapharmacy:certificationsDescription')}
          </p>
        </div>
        <Button onClick={() => navigate('/parapharmacy/certifications/new')}>
          <Plus className="h-4 w-4 mr-2" />
          {t('parapharmacy:addCertification')}
        </Button>
      </div>

      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('parapharmacy:name')}</TableHead>
              <TableHead>{t('parapharmacy:type')}</TableHead>
              <TableHead>{t('parapharmacy:certifyingBody')}</TableHead>
              <TableHead>{t('parapharmacy:status')}</TableHead>
              <TableHead>{t('parapharmacy:displayOrder')}</TableHead>
              <TableHead className="text-end">{t('common:actions')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {data?.data.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="text-center text-muted-foreground">
                  {t('common:noData')}
                </TableCell>
              </TableRow>
            )}
            {data?.data.map((certification) => (
              <TableRow key={certification.id}>
                <TableCell className="font-medium">{certification.name}</TableCell>
                <TableCell>
                  <code className="text-xs bg-muted px-2 py-1 rounded">
                    {certification.type}
                  </code>
                </TableCell>
                <TableCell>{certification.certifying_body || '—'}</TableCell>
                <TableCell>
                  {certification.is_active ? (
                    <Badge variant="default">
                      {t('parapharmacy:active')}
                    </Badge>
                  ) : (
                    <Badge variant="secondary">
                      {t('parapharmacy:inactive')}
                    </Badge>
                  )}
                </TableCell>
                <TableCell>{certification.display_order}</TableCell>
                <TableCell className="text-end">
                  <div className="flex items-center justify-end gap-2">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() =>
                        navigate(`/parapharmacy/certifications/${certification.id}`)
                      }
                    >
                      <Edit className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => setDeleteId(certification.id)}
                    >
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      {data?.meta.pagination && (
        <OffsetPagination
          currentPage={page}
          totalPages={Math.ceil(
            data.meta.pagination.total / data.meta.pagination.per_page
          )}
          onPageChange={setPage}
        />
      )}

      <AlertDialog open={!!deleteId} onOpenChange={() => setDeleteId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t('parapharmacy:confirmDeleteCertification')}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {t('parapharmacy:confirmDeleteCertificationDescription')}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('common:cancel')}</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => deleteId && deleteMutation.mutate(deleteId)}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {t('common:delete')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
