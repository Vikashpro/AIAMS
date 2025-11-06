<template>
  <header class="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 w-full">
    <div class="container mx-auto">
      <nav class="p-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex items-center justify-between gap-4">
          <div class="flex items-center gap-4">
            <Link :href="route('documents.index')" class="text-lg font-medium text-gray-700 dark:text-gray-200">Documents</Link>
            <Link :href="route('documents.search')" class="text-lg font-medium text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white">Search</Link>
          </div>
          <div class="text-xl text-indigo-600 dark:text-indigo-300 font-bold">
            <Link :href="route('documents.index')">AI-AMS</Link>
          </div>
        </div>
        <div v-if="user" class="flex flex-col lg:flex-row lg:items-center gap-3 lg:gap-4">
          <div class="text-sm text-gray-500 dark:text-gray-300">
            <span class="font-medium text-gray-700 dark:text-white">{{ user.name }}</span>
            <span class="mx-2">•</span>
            <span class="uppercase tracking-wide">{{ user.role }}</span>
            <span v-if="user.department" class="block lg:inline text-xs text-gray-400 lg:ml-2">{{ user.department.name }}</span>
          </div>

          <div class="flex items-center gap-3">
            <Link
              class="text-gray-500 relative pr-2 py-2 text-lg"
              :href="route('notification.index')"
            >
              🔔
              <div v-if="notificationCount" class="absolute right-0 top-0 w-5 h-5 bg-red-700 dark:bg-red-400 text-white font-medium border border-white dark:border-gray-900 rounded-full text-xs text-center">
                {{ notificationCount }}
              </div>
            </Link>

            <Link
              v-if="canUpload"
              :href="route('documents.create')"
              class="btn-primary"
            >
              + Upload Document
            </Link>
            <Link :href="route('logout')" method="delete" as="button">Logout</Link>
          </div>
        </div>
        <div v-else class="flex items-center gap-2">
          <Link :href="route('user-account.create')">Register</Link>
          <Link :href="route('login')">Sign-In</Link>
        </div>
      </nav>
    </div>
  </header>
  
  <main class="container mx-auto p-4 w-full">
    <div v-if="flashSuccess" class="mb-4 border rounded-md shadow-sm border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900 p-2">
      {{ flashSuccess }}
    </div>
    <slot></slot>
  </main>
</template>

<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const page = usePage()
const flashSuccess = computed(
  () => page.props.flash.success,
)
const user = computed(
  () => page.props.user,
)
const notificationCount = computed(
  () => Math.min(page.props.user?.notificationCount ?? 0, 9) || null,
)
const canUpload = computed(
  () => !!user.value && user.value.role !== 'auditor',
)
</script>