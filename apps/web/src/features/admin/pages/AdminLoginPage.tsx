import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { Shield, Loader2 } from 'lucide-react'
import { loginSuperAdmin } from '../api'
import { useAdminAuthStore } from '../stores/adminAuthStore'

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
    <div className="min-h-screen flex items-center justify-center bg-gray-900 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-md w-full space-y-8">
        <div className="text-center">
          <div className="mx-auto h-16 w-16 flex items-center justify-center rounded-full bg-blue-600">
            <Shield className="h-10 w-10 text-white" />
          </div>
          <h2 className="mt-6 text-3xl font-bold text-white">
            Super Admin Portal
          </h2>
          <p className="mt-2 text-sm text-gray-400">
            Sign in to access the administration panel
          </p>
        </div>

        <form className="mt-8 space-y-6" onSubmit={handleSubmit}>
          {error && (
            <div className="rounded-lg bg-red-900/50 border border-red-500 p-4 text-sm text-red-200">
              {error}
            </div>
          )}

          <div className="space-y-4">
            <div>
              <label htmlFor="email" className="block text-sm font-medium text-gray-300">
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
                className="mt-1 block w-full rounded-lg border border-gray-600 bg-gray-800 px-4 py-3 text-white placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                placeholder="superadmin@mecanospex.com"
              />
            </div>

            <div>
              <label htmlFor="password" className="block text-sm font-medium text-gray-300">
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
                className="mt-1 block w-full rounded-lg border border-gray-600 bg-gray-800 px-4 py-3 text-white placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                placeholder="Enter your password"
              />
            </div>
          </div>

          <button
            type="submit"
            disabled={loginMutation.isPending}
            className="w-full flex justify-center items-center gap-2 rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-gray-900 disabled:opacity-50 disabled:cursor-not-allowed"
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
            className="text-sm text-gray-400 hover:text-gray-300"
          >
            &larr; Back to regular login
          </a>
        </div>
      </div>
    </div>
  )
}
