<template>
  <section class="space-y-6">
    <header class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
      <div>
        <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">{{ document.title }}</h1>
        <div class="mt-1 text-sm text-gray-500 dark:text-gray-400 flex flex-wrap gap-3 items-center">
          <span v-if="document.department">{{ document.department }}</span>
          <span v-if="document.fiscal_year">FY {{ document.fiscal_year }}</span>
          <span
            class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold"
            :class="statusBadgeClass"
          >
            {{ statusLabel }}
          </span>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <a :href="document.file_path" class="btn-outline" target="_blank" rel="noopener">Download file</a>
        <Link :href="route('documents.index')" class="btn-outline">Back to documents</Link>
      </div>
    </header>

    <section class="grid grid-cols-1 lg:grid-cols-[2fr,1fr] gap-6">
      <form
        v-if="canEdit"
        @submit.prevent="submit"
        class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-md shadow-sm p-6 space-y-5"
      >
        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Metadata &amp; notes</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="label">Title</label>
            <input v-model="form.title" class="input" required />
            <FormError :message="form.errors.title" />
          </div>
          <div>
            <label class="label">Fiscal year</label>
            <input v-model="form.fiscal_year" class="input" />
            <FormError :message="form.errors.fiscal_year" />
          </div>
        </div>

        <div>
          <label class="label">Tags</label>
          <input v-model="form.tags" class="input" placeholder="Comma separated" />
          <FormError :message="form.errors.tags" />
        </div>

        <div>
          <label class="label">Document text</label>
          <textarea v-model="form.document_text" rows="8" class="input" placeholder="Paste OCR output or manual notes"></textarea>
          <FormError :message="form.errors.document_text" />
        </div>

        <div>
          <label class="label">Summary</label>
          <textarea v-model="form.summary" rows="6" class="input" placeholder="Add a concise executive summary"></textarea>
          <FormError :message="form.errors.summary" />
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
          <div>
            <label class="label">Status</label>
            <select v-model="form.status" class="input">
              <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
            <FormError :message="form.errors.status" />
          </div>
          <div class="flex items-center gap-3 md:justify-end">
            <button type="button" class="btn-outline" @click="generateSummary" :disabled="summaryProcessing">
              <span v-if="summaryProcessing">Generating...</span>
              <span v-else>Generate draft summary</span>
            </button>
            <button type="submit" class="btn-primary" :disabled="form.processing">
              <span v-if="form.processing">Saving...</span>
              <span v-else>Save changes</span>
            </button>
          </div>
        </div>
      </form>

      <aside class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-md shadow-sm p-6 space-y-4">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Document details</h2>
        <dl class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
          <div>
            <dt class="font-medium text-gray-500 dark:text-gray-400">Uploaded by</dt>
            <dd>{{ document.uploader || '—' }}</dd>
          </div>
          <div>
            <dt class="font-medium text-gray-500 dark:text-gray-400">Uploaded on</dt>
            <dd>{{ document.created_at }}</dd>
          </div>
          <div>
            <dt class="font-medium text-gray-500 dark:text-gray-400">Last updated</dt>
            <dd>{{ document.updated_at }}</dd>
          </div>
          <div v-if="document.tags.length">
            <dt class="font-medium text-gray-500 dark:text-gray-400">Tags</dt>
            <dd>{{ document.tags.join(', ') }}</dd>
          </div>
        </dl>

        <div>
          <h3 class="text-sm font-semibold uppercase text-gray-500 dark:text-gray-400">Activity</h3>
          <ul class="mt-2 space-y-2 text-sm text-gray-600 dark:text-gray-300 max-h-72 overflow-y-auto">
            <li v-for="activity in activities" :key="activity.id" class="border-b border-gray-200 dark:border-gray-700 pb-2 last:border-0 last:pb-0">
              <div class="font-medium text-gray-800 dark:text-gray-200">{{ activity.type }}</div>
              <div>{{ activity.description }}</div>
              <div class="text-xs text-gray-400">{{ activity.user ? `by ${activity.user}` : 'system' }} • {{ activity.created_at }}</div>
            </li>
            <li v-if="!activities.length" class="text-gray-400 italic">No activity recorded yet.</li>
          </ul>
        </div>
      </aside>
    </section>

    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-md shadow-sm p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Summary</h2>
        <p class="mt-2 text-sm text-gray-700 dark:text-gray-200 whitespace-pre-wrap">
          <span v-if="document.summary && document.summary.length">{{ document.summary }}</span>
          <span v-else class="italic text-gray-400">No summary available yet.</span>
        </p>
      </div>
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-md shadow-sm p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Document text</h2>
        <p class="mt-2 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200">
          {{ document.document_text || 'No text available yet.' }}
        </p>
      </div>
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-md shadow-sm p-6 flex flex-col gap-4">
        <div>
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">AI insights</h2>
          <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Ask natural language questions about this record.</p>
        </div>

        <form @submit.prevent="askQuestion" class="space-y-3">
          <div>
            <label class="label">Question</label>
            <textarea
              v-model="analysisForm.question"
              class="input"
              rows="4"
              placeholder="e.g. Highlight key audit issues and recommended actions"
            ></textarea>
            <FormError :message="analysisForm.errors.question" />
          </div>
          <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary" :disabled="analysisForm.processing">
              <span v-if="analysisForm.processing">Analysing...</span>
              <span v-else>Ask</span>
            </button>
            <span v-if="modelLabel" class="text-xs text-gray-400">Model: {{ modelLabel }}</span>
          </div>
        </form>

        <div v-if="latestAnalysis" class="border-t border-gray-200 dark:border-gray-700 pt-4 space-y-3">
          <div>
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Latest response</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300"><span class="font-semibold">Q:</span> {{ latestAnalysis.question }}</p>
            <p class="mt-2 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200">
              <span class="font-semibold">A:</span> {{ latestAnalysis.answer }}
            </p>
          </div>
          <div v-if="latestAnalysis.sources && latestAnalysis.sources.length" class="space-y-1">
            <h4 class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Sources</h4>
            <ul class="space-y-2 text-xs text-gray-500 dark:text-gray-300">
              <li
                v-for="source in latestAnalysis.sources"
                :key="source.chunk_index"
                class="border border-dashed border-gray-200 dark:border-gray-700 rounded p-2"
              >
                <div class="font-semibold text-gray-700 dark:text-gray-200">
                  Chunk {{ source.chunk_index }} • Score {{ formatScore(source.score) }}
                </div>
                <div class="mt-1 italic">{{ source.excerpt }}</div>
              </li>
            </ul>
          </div>
        </div>
        <p v-else class="text-sm text-gray-400 italic">Ask a question to generate an AI-backed insight.</p>
      </div>
    </section>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import FormError from '@/Components/UI/FormError.vue'

const props = defineProps({
  document: Object,
  activities: Array,
  analysis: {
    type: Object,
    default: null,
  },
})

const statusOptions = [
  { value: 'manual', label: 'Manual' },
  { value: 'pending_ocr', label: 'Pending OCR' },
  { value: 'summarized', label: 'Summarized' },
]

const user = computed(() => usePage().props.user)
const canEdit = computed(() => {
  if (!user.value) return false
  if (user.value.role === 'admin') {
    return true
  }
  if (!props.document.department_id) {
    return false
  }
  return user.value.department?.id === props.document.department_id
})

const form = useForm({
  title: props.document.title,
  fiscal_year: props.document.fiscal_year || '',
  tags: props.document.tags.join(', '),
  document_text: props.document.document_text || '',
  summary: props.document.summary || '',
  status: props.document.status,
})

const analysisForm = useForm({
  question: '',
})

watch(
  () => props.document,
  document => {
    form.title = document.title
    form.fiscal_year = document.fiscal_year || ''
    form.tags = document.tags.join(', ')
    form.document_text = document.document_text || ''
    form.summary = document.summary || ''
    form.status = document.status
  },
  { deep: false }
)

const statusLabel = computed(() => {
  const option = statusOptions.find(option => option.value === props.document.status)
  return option ? option.label : props.document.status
})

const statusBadgeClass = computed(() => {
  switch (props.document.status) {
    case 'summarized':
      return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
    case 'pending_ocr':
      return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'
    default:
      return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'
  }
})

const summaryProcessing = ref(false)
const latestAnalysis = ref(props.analysis ?? null)
const modelLabel = computed(() => latestAnalysis.value?.model ?? '')

const submit = () => {
  form.put(route('documents.update', { document: props.document.id }), {
    preserveScroll: true,
  })
}

const generateSummary = () => {
  summaryProcessing.value = true
  router.post(route('documents.generate-summary', { document: props.document.id }), {}, {
    preserveScroll: true,
    onFinish: () => {
      summaryProcessing.value = false
    },
  })
}

const askQuestion = () => {
  analysisForm.post(route('documents.analyze', { document: props.document.id }), {
    preserveScroll: true,
    onSuccess: () => {
      analysisForm.reset('question')
    },
  })
}

watch(
  () => props.analysis,
  analysis => {
    latestAnalysis.value = analysis ?? null
  }
)

const formatScore = score => {
  if (typeof score !== 'number') {
    return score
  }

  return score.toFixed(3)
}
</script>
