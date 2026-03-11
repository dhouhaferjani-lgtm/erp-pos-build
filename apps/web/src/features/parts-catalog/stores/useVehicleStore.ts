import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import type { SelectedVehicle } from '../types/catalog'

const MAX_HISTORY = 10

interface VehicleState {
  selectedVehicle: SelectedVehicle | null
  vehicleHistory: SelectedVehicle[]
}

interface VehicleActions {
  selectVehicle: (vehicle: SelectedVehicle) => void
  clearVehicle: () => void
  selectFromHistory: (vehicleId: string) => void
}

export const useVehicleStore = create<VehicleState & VehicleActions>()(
  persist(
    (set) => ({
      selectedVehicle: null,
      vehicleHistory: [],

      selectVehicle: (vehicle) => {
        set((state) => {
          const filtered = state.vehicleHistory.filter((v) => v.id !== vehicle.id)
          const history = [vehicle, ...filtered].slice(0, MAX_HISTORY)
          return { selectedVehicle: vehicle, vehicleHistory: history }
        })
      },

      clearVehicle: () => {
        set({ selectedVehicle: null })
      },

      selectFromHistory: (vehicleId) => {
        set((state) => {
          const vehicle = state.vehicleHistory.find((v) => v.id === vehicleId)
          if (!vehicle) return state
          const filtered = state.vehicleHistory.filter((v) => v.id !== vehicleId)
          const updatedVehicle = { ...vehicle, selectedAt: new Date().toISOString() }
          return {
            selectedVehicle: updatedVehicle,
            vehicleHistory: [updatedVehicle, ...filtered],
          }
        })
      },
    }),
    {
      name: 'parts-catalog-vehicle',
      partialize: (state) => ({
        selectedVehicle: state.selectedVehicle,
        vehicleHistory: state.vehicleHistory,
      }),
    }
  )
)
