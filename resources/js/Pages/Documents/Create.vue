<template>
  <section class="max-w-4xl mx-auto space-y-6">
    <header class="space-y-1">
      <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Upload document</h1>
      <p class="text-sm text-gray-500 dark:text-gray-400">
        Provide a file, basic metadata, and optional manual text while OCR and AI automation are configured.
      </p>
    </header>

    <form @submit.prevent="submit" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-md shadow-sm p-6 space-y-6">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <label class="label">Title</label>
          <input v-model="form.title" class="input" required />
          <FormError :message="form.errors.title" />
        </div>
        <div>
          <label class="label">Department</label>
          <select v-model="form.department_id" class="input">
            <option value="">Select department</option>
            <option v-for="department in departments" :key="department.id" :value="department.id">
              {{ department.name }}
            </option>
          </select>
          <FormError :message="form.errors.department_id" />
        </div>
        <div>
          <label class="label">Fiscal year</label>
          <input v-model="form.fiscal_year" class="input" placeholder="e.g. 2021-2022" />
          <FormError :message="form.errors.fiscal_year" />
        </div>
        <div>
          <label class="label">Tags</label>
          <input v-model="form.tags" class="input" placeholder="Comma separated e.g. audit, finance" />
          <FormError :message="form.errors.tags" />
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div>
          <label class="label">Document file</label>
          <input class="input" type="file" @change="onFileChange" required />
          <p class="text-xs text-gray-500 mt-1">PDF, Word, or image files are supported for the MVP.</p>
          <FormError :message="form.errors.file" />
        </div>
        <div>
          <label class="label">Initial status</label>
          <select v-model="form.status" class="input">
            <option value="manual">Manual</option>
            <option value="pending_ocr">Pending OCR</option>
          </select>
          <FormError :message="form.errors.status" />
        </div>
      </div>

      <div>
        <label class="label">Document text (optional)</label>
        <textarea v-model="form.document_text" class="input" rows="6" placeholder="Paste extracted text or notes"></textarea>
        <FormError :message="form.errors.document_text" />
      </div>

      <div class="flex items-center gap-3">
        <button type="submit" class="btn-primary" :disabled="form.processing">
          <span v-if="form.processing">Uploading...</span>
          <span v-else>Upload document</span>
        </button>
        <Link :href="route('documents.index')" class="btn-outline">Cancel</Link>
      </div>
    </form>
  </section>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import FormError from '@/Components/UI/FormError.vue'

const props = defineProps({
  departments: Array,
})

const form = useForm({
  title: '',
  department_id: '',
  fiscal_year: '',
  tags: '',
  file: null,
  document_text: '',
  status: 'manual',
})

const onFileChange = event => {
  form.file = event.target.files[0]
}

const submit = () => {
  form.post(route('documents.store'), {
    forceFormData: true,
    onSuccess: () => {
      form.reset()
      form.status = 'manual'
    },
  })
}
</script>
