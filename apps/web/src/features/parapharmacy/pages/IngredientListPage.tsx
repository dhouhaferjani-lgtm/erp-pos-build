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
import { fetchIngredients, deleteIngredient } from '../api/ingredientApi';
import { toast } from 'sonner';
import { OffsetPagination } from '@/components/ui/OffsetPagination';

export function IngredientListPage() {
  const { t } = useTranslation(['common', 'parapharmacy']);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [deleteId, setDeleteId] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['parapharmacy', 'ingredients', page],
    queryFn: () => fetchIngredients({ page, per_page: 25 }),
  });

  const deleteMutation = useMutation({
    mutationFn: deleteIngredient,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parapharmacy', 'ingredients'] });
      toast.success(t('parapharmacy:ingredientDeleted'));
      setDeleteId(null);
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:deleteIngredientError');
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
          <h1 className="text-3xl font-bold">{t('parapharmacy:ingredients')}</h1>
          <p className="text-muted-foreground">
            {t('parapharmacy:ingredientsDescription')}
          </p>
        </div>
        <Button onClick={() => navigate('/parapharmacy/ingredients/new')}>
          <Plus className="h-4 w-4 mr-2" />
          {t('parapharmacy:addIngredient')}
        </Button>
      </div>

      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('parapharmacy:name')}</TableHead>
              <TableHead>{t('parapharmacy:slug')}</TableHead>
              <TableHead>{t('parapharmacy:casNumber')}</TableHead>
              <TableHead>{t('parapharmacy:allergen')}</TableHead>
              <TableHead>{t('parapharmacy:regulatoryStatus')}</TableHead>
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
            {data?.data.map((ingredient) => (
              <TableRow key={ingredient.id}>
                <TableCell className="font-medium">{ingredient.name}</TableCell>
                <TableCell>
                  <code className="text-xs bg-muted px-2 py-1 rounded">
                    {ingredient.slug}
                  </code>
                </TableCell>
                <TableCell>{ingredient.cas_number || '—'}</TableCell>
                <TableCell>
                  {ingredient.is_allergen ? (
                    <Badge variant="destructive">
                      {t('parapharmacy:allergen')}
                    </Badge>
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </TableCell>
                <TableCell>
                  {ingredient.regulatory_status ? (
                    <Badge
                      variant={
                        ingredient.regulatory_status === 'approved'
                          ? 'default'
                          : ingredient.regulatory_status === 'restricted'
                          ? 'secondary'
                          : 'destructive'
                      }
                    >
                      {t(`parapharmacy:regulatoryStatus.${ingredient.regulatory_status}`)}
                    </Badge>
                  ) : (
                    '—'
                  )}
                </TableCell>
                <TableCell className="text-end">
                  <div className="flex items-center justify-end gap-2">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() =>
                        navigate(`/parapharmacy/ingredients/${ingredient.id}`)
                      }
                    >
                      <Edit className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => setDeleteId(ingredient.id)}
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
              {t('parapharmacy:confirmDeleteIngredient')}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {t('parapharmacy:confirmDeleteIngredientDescription')}
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
