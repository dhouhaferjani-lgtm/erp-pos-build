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
import { fetchKeyComponents, deleteKeyComponent } from '../api/keyComponentApi';
import { toast } from 'sonner';
import { OffsetPagination } from '@/components/ui/OffsetPagination';

export function KeyComponentListPage() {
  const { t } = useTranslation(['common', 'parapharmacy']);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [deleteId, setDeleteId] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['parapharmacy', 'key-components', page],
    queryFn: () => fetchKeyComponents({ page, per_page: 25 }),
  });

  const deleteMutation = useMutation({
    mutationFn: deleteKeyComponent,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parapharmacy', 'key-components'] });
      toast.success(t('parapharmacy:keyComponentDeleted'));
      setDeleteId(null);
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:deleteKeyComponentError');
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
          <h1 className="text-3xl font-bold">{t('parapharmacy:keyComponents')}</h1>
          <p className="text-muted-foreground">
            {t('parapharmacy:keyComponentsDescription')}
          </p>
        </div>
        <Button onClick={() => navigate('/parapharmacy/key-components/new')}>
          <Plus className="h-4 w-4 mr-2" />
          {t('parapharmacy:addKeyComponent')}
        </Button>
      </div>

      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('parapharmacy:name')}</TableHead>
              <TableHead>{t('parapharmacy:slug')}</TableHead>
              <TableHead>{t('parapharmacy:allergen')}</TableHead>
              <TableHead className="text-end">{t('common:actions')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {data?.data.length === 0 && (
              <TableRow>
                <TableCell colSpan={4} className="text-center text-muted-foreground">
                  {t('common:noData')}
                </TableCell>
              </TableRow>
            )}
            {data?.data.map((keyComponent) => (
              <TableRow key={keyComponent.id}>
                <TableCell className="font-medium">{keyComponent.name}</TableCell>
                <TableCell>
                  <code className="text-xs bg-muted px-2 py-1 rounded">
                    {keyComponent.slug}
                  </code>
                </TableCell>
                <TableCell>
                  {keyComponent.is_allergen ? (
                    <Badge variant="destructive">
                      {t('parapharmacy:allergen')}
                    </Badge>
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </TableCell>
                <TableCell className="text-end">
                  <div className="flex items-center justify-end gap-2">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() =>
                        navigate(`/parapharmacy/key-components/${keyComponent.id}`)
                      }
                    >
                      <Edit className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => setDeleteId(keyComponent.id)}
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
              {t('parapharmacy:confirmDeleteKeyComponent')}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {t('parapharmacy:confirmDeleteKeyComponentDescription')}
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
