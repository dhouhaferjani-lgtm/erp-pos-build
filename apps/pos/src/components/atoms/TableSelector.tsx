import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { getFloors } from '@/api/tableApi';
import type { FloorData, TableData } from '@/api/tableApi';

const STATUS_STYLES: Record<string, string> = {
  available: 'bg-green-100 text-green-800',
  occupied: 'bg-red-100 text-red-800',
  reserved: 'bg-purple-100 text-purple-800',
  cleaning: 'bg-yellow-100 text-yellow-800',
};

function TableStatusBadge({ status }: { status: string }) {
  return (
    <span
      className={`inline-flex rounded-pill px-2.5 py-0.5 text-xs font-medium ${STATUS_STYLES[status] ?? 'bg-gray-100 text-gray-800'}`}
    >
      {status}
    </span>
  );
}

interface TableSelectorProps {
  selectedTableId: string | null;
  onSelectTable: (tableId: string | null) => void;
}

export function TableSelector({ selectedTableId, onSelectTable }: TableSelectorProps) {
  const { t } = useTranslation();
  const [floors, setFloors] = useState<FloorData[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setIsLoading(true);
    getFloors()
      .then((data) => {
        if (!cancelled) {
          setFloors(data);
          setIsLoading(false);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setFloors([]);
          setIsLoading(false);
        }
      });
    return () => { cancelled = true; };
  }, []);

  if (isLoading) {
    return (
      <div className="py-4 text-center text-sm text-gray-400">
        {t('products.loading', 'Loading...')}
      </div>
    );
  }

  const allTables = floors.flatMap((floor) => floor.tables ?? []);
  if (allTables.length === 0) {
    return null;
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700">
          {t('tables.selectTable')}
        </h3>
        {selectedTableId && (
          <button
            type="button"
            onClick={() => onSelectTable(null)}
            className="text-xs text-blue-600 hover:text-blue-800"
          >
            {t('tables.clearSelection')}
          </button>
        )}
      </div>

      {floors.map((floor) => {
        const tables = floor.tables ?? [];
        if (tables.length === 0) return null;

        return (
          <div key={floor.id}>
            <p className="mb-2 text-xs font-medium uppercase tracking-wider text-gray-500">
              {floor.name}
            </p>
            <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
              {tables.map((table: TableData) => {
                const isSelected = selectedTableId === table.id;
                const isAvailable = table.status === 'available';

                return (
                  <button
                    key={table.id}
                    type="button"
                    disabled={!isAvailable && !isSelected}
                    onClick={() => onSelectTable(isSelected ? null : table.id)}
                    className={`relative rounded-tile border-2 p-3 text-center transition-all ${
                      isSelected
                        ? 'border-blue-500 bg-blue-50'
                        : isAvailable
                          ? 'border-gray-200 bg-white hover:border-blue-300'
                          : 'cursor-not-allowed border-gray-100 bg-gray-50 opacity-50'
                    }`}
                  >
                    <div className="text-sm font-bold text-gray-900">
                      {table.table_number}
                    </div>
                    {table.label && (
                      <div className="mt-0.5 truncate text-xs text-gray-500">
                        {table.label}
                      </div>
                    )}
                    <div className="mt-1 text-xs text-gray-400">
                      {table.seats} {t('tables.seats')}
                    </div>
                    <div className="mt-1">
                      <TableStatusBadge status={table.status} />
                    </div>
                  </button>
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}
