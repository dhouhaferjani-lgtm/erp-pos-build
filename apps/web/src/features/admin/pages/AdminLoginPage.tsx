import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { Shield, Loader2 } from 'lucide-react'
import { loginSuperAdmin } from '../api'
import { useAdminAuthStore } from '../stores/adminAuthStore'
import { colorClasses } from '@/lib/designTokens'

export function AdminLoginPage() {
  const navigate = useNavigate()
  const setAuth = useAdminAuthStore((state) => state.setAuth)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  const loginMutation = useMutation({
    mutationFn: () => loginSuperAdmin(email, password),
    onSuccess: (data) => {
      // Memory-only Bearer token; also works alongside the session cookie
      // on cookie-capable deploys.
      setAuth(
        {
          id: data.admin.id,
          email: data.admin.email,
          name: data.admin.name,
          role: data.admin.role,
        },
        data.token
      )
      navigate('/admin/dashboard')
    },
    onError: (err: Error) => {
      setError(err.message || 'Invalid credentials')
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setError('')
    loginMutation.mutate()
  }

  return (
    <div className={`min-h-screen flex items-center justify-center ${colorClasses.bgGray900} py-12 px-4 sm:px-6 lg:px-8`}>
      <div className="max-w-md w-full space-y-8">
        <div className="text-center">
          <div className={`mx-auto h-16 w-16 flex items-center justify-center rounded-full ${colorClasses.bgBlue600}`}>
            <Shield className="h-10 w-10 text-white" />
          </div>
          <h2 className="mt-6 text-3xl font-bold text-white">
            Super Admin Portal
          </h2>
          <p className={`mt-2 text-sm ${colorClasses.textGray400}`}>
            Sign in to access the administration panel
          </p>
        </div>

        <form className="mt-8 space-y-6" onSubmit={handleSubmit}>
          {error && (
            <div className={`rounded-lg ${colorClasses.bgRed90050} border ${colorClasses.borderRed500} p-4 text-sm ${colorClasses.textRed200}`}>
              {error}
            </div>
          )}

          <div className="space-y-4">
            <div>
              <label htmlFor="email" className={`block text-sm font-medium ${colorClasses.textGray300}`}>
                Email address
              </label>
              <input
                id="email"
                name="email"
                type="email"
                autoComplete="email"
                required
                value={email}
                onChange={(e) => { setEmail(e.target.value); }}
                className={`mt-1 block w-full rounded-lg border ${colorClasses.borderGray600} ${colorClasses.bgGray800} px-4 py-3 text-white ${colorClasses.placeholderGray400} ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
                placeholder="superadmin@mecanospex.com"
              />
            </div>

            <div>
              <label htmlFor="password" className={`block text-sm font-medium ${colorClasses.textGray300}`}>
                Password
              </label>
              <input
                id="password"
                name="password"
                type="password"
                autoComplete="current-password"
                required
                value={password}
                onChange={(e) => { setPassword(e.target.value); }}
                className={`mt-1 block w-full rounded-lg border ${colorClasses.borderGray600} ${colorClasses.bgGray800} px-4 py-3 text-white ${colorClasses.placeholderGray400} ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
                placeholder="Enter your password"
              />
            </div>
          </div>

          <button
            type="submit"
            disabled={loginMutation.isPending}
            className={`w-full flex justify-center items-center gap-2 rounded-lg ${colorClasses.bgBlue600} px-4 py-3 text-sm font-semibold text-white ${colorClasses.hoverBgBlue700} focus:outline-none focus:ring-2 ${colorClasses.focusRingBlue500} focus:ring-offset-2 focus:ring-offset-gray-900 disabled:opacity-50 disabled:cursor-not-allowed`}
          >
            {loginMutation.isPending ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" />
                Signing in...
              </>
            ) : (
              'Sign in'
            )}
          </button>
        </form>

        <div className="text-center">
          <a
            href="/login"
            className={`text-sm ${colorClasses.textGray400} ${colorClasses.hoverTextGray300}`}
          >
            &larr; Back to regular login
          </a>
        </div>
      </div>
    </div>
  )
}
