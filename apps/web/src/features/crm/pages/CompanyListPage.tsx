import { useNavigate } from 'react-router-dom'
import { useEffect } from 'react'

export function CompanyListPage() {
  const navigate = useNavigate()
  // Redirect to the existing customers page for now
  useEffect(() => {
    navigate('/sales/customers', { replace: true })
  }, [navigate])
  return null
}
