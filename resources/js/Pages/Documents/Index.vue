<template>
  <section class="space-y-6">
    <header class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
      <div>
        <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Document Repository</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">
          Search and filter archival records across departments.
        </p>
      </div>
      <Link
        v-if="canUpload"
        :href="route('documents.create')"
        class="btn-primary self-start"
      >
        + Upload Document
      </Link>
    </header>

    <form @submit.prevent="applyFilters" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-md p-4 shadow-sm space-y-4">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div>
          <label class="label">Department</label>
          <select v-model="form.department_id" class="input">
            <option value="">All departments</option>
            <option v-for="department in departments" :key="department.id" :value="department.id">
              {{ department.name }}
            </option>
          </select>
        </div>
        <div>
          <label class="label">Fiscal year</label>
          <input v-model="form.fiscal_year" class="input" placeholder="e.g. 2022-2023" />
        </div>
        <div>
          <label class="label">Status</label>
          <select v-model="form.status" class="input">
            <option value="">Any status</option>
            <option v-for="option in statusOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </div>
        <div>
          <label class="label">Keyword</label>
          <input v-model="form.keyword" class="input" placeholder="Search title, text, or tags" />
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="btn-primary">Apply filters</button>
        <button type="button" class="btn-outline" @click="resetFilters">Reset</button>
      </div>
    </form>

    <section v-if="documents.data.length" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-md shadow-sm">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead class="bg-gray-50 dark:bg-gray-800">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Title</th>
              <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Department</th>
              <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Fiscal year</th>
              <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Status</th>
              <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">Summary</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
            <tr v-for="document in documents.data" :key="document.id" class="hover:bg-gray-50 dark:hover:bg-gray-800">
              <td class="px-4 py-3">
                <div class="font-medium text-gray-900 dark:text-white">{{ document.title }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400" v-if="document.tags.length">
                  Tags: {{ document.tags.join(', ') }}
                </div>
              </td>
              <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                {{ document.department || '—' }}
              </td>
              <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                {{ document.fiscal_year || '—' }}
              </td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold" :class="statusClass(document.status)">
                  {{ statusLabel(document.status) }}
                </span>
              </td>
              <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                <span v-if="document.summary">{{ document.summary }}</span>
                <span v-else class="italic text-gray-400">No summary yet</span>
              </td>
              <td class="px-4 py-3 text-right">
                <Link :href="route('documents.show', { document: document.id })" class="text-indigo-600 dark:text-indigo-300 font-medium">View</Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="p-4 border-t border-gray-200 dark:border-gray-700">
        <Pagination :links="documents.links" />
      </div>
    </section>
    <EmptyState v-else>No documents match the current filters.</EmptyState>
  </section>
</template>

<script setup>
import { computed, reactive } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import EmptyState from '@/Components/UI/EmptyState.vue'
import Pagination from '@/Components/UI/Pagination.vue'

const props = defineProps({
  documents: Object,
  departments: Array,
  filters: Object,
  statusOptions: Array,
})

const user = computed(() => usePage().props.user)
const canUpload = computed(() => !!user.value && user.value.role !== 'auditor')

const form = reactive({
  department_id: props.filters.department_id ?? '',
  fiscal_year: props.filters.fiscal_year ?? '',
  status: props.filters.status ?? '',
  keyword: props.filters.keyword ?? '',
})

const applyFilters = () => {
  router.get(route('documents.index'), form, {
    preserveScroll: true,
    preserveState: true,
    replace: true,
  })
}

const resetFilters = () => {
  form.department_id = ''
  form.fiscal_year = ''
  form.status = ''
  form.keyword = ''
  applyFilters()
}

const statusLabel = status => {
  const mapping = Object.fromEntries(props.statusOptions.map(option => [option.value, option.label]))
  return mapping[status] ?? status
}

const statusClass = status => {
  switch (status) {
    case 'summarized':
      return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
    case 'pending_ocr':
      return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'
    default:
      return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'
  }
}
</script>
