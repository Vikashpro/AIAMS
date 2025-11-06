<template>
  <section class="space-y-6">
    <header class="space-y-1">
      <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Search Archive</h1>
      <p class="text-sm text-gray-500 dark:text-gray-400">
        Look up indexed documents and jump straight to the relevant passages.
      </p>
    </header>

    <form
      @submit.prevent="submit"
      class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-md p-4 shadow-sm space-y-4"
    >
      <div class="flex flex-col md:flex-row gap-3">
        <input
          v-model="localQuery"
          type="search"
          class="input flex-1"
          placeholder="Search by keyword, phrase, or policy topic"
          autofocus
        />
        <button type="submit" class="btn-primary self-start md:self-auto">Search</button>
      </div>
      <p
        v-if="!searchEnabled"
        class="text-xs text-amber-600 dark:text-amber-400"
      >
        Elasticsearch is not configured—results fall back to a basic database scan.
      </p>
    </form>

    <section v-if="hasResults" class="space-y-4">
      <article
        v-for="result in results.data"
        :key="result.id"
        class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-md shadow-sm p-5 space-y-3"
      >
        <header class="flex flex-col md:flex-row md:items-start md:justify-between gap-2">
          <div>
            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">{{ result.title }}</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 flex flex-wrap gap-2 items-center">
              <span v-if="result.department">{{ result.department }}</span>
              <span v-if="result.fiscal_year">• FY {{ result.fiscal_year }}</span>
              <span v-if="result.status" class="uppercase tracking-wide text-[10px] font-semibold bg-indigo-100 dark:bg-indigo-900 text-indigo-700 dark:text-indigo-200 px-2 py-0.5 rounded-full">
                {{ result.status }}
              </span>
            </p>
          </div>
          <div class="flex gap-2">
            <Link
              :href="route('documents.show', { document: result.id })"
              class="btn-outline"
            >
              View
            </Link>
            <a
              v-if="result.download_url"
              :href="result.download_url"
              class="btn-primary"
              target="_blank"
              rel="noopener"
            >
              Download
            </a>
            <span
              v-else
              class="btn-outline opacity-50 cursor-not-allowed select-none"
              aria-disabled="true"
            >
              Download unavailable
            </span>
          </div>
        </header>
        <p
          v-if="result.snippet"
          class="text-sm leading-relaxed text-gray-700 dark:text-gray-200"
          v-html="result.snippet"
        ></p>
        <p v-else class="text-sm text-gray-400 italic">No preview available for this document.</p>
      </article>

      <Pagination :links="results.links" />
    </section>

    <EmptyState v-else-if="query">
      No documents found for “{{ query }}”. Try refining your keywords.
    </EmptyState>

    <EmptyState v-else>
      Start typing above to search across the archive.
    </EmptyState>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import EmptyState from '@/Components/UI/EmptyState.vue'
import Pagination from '@/Components/UI/Pagination.vue'

const props = defineProps({
  query: {
    type: String,
    default: '',
  },
  results: {
    type: Object,
    required: true,
  },
  searchEnabled: {
    type: Boolean,
    default: true,
  },
})

const localQuery = ref(props.query)

watch(
  () => props.query,
  value => {
    localQuery.value = value
  }
)

const hasResults = computed(() => props.results?.data?.length > 0)

const submit = () => {
  router.get(
    route('documents.search'),
    { q: localQuery.value },
    {
      preserveState: true,
      replace: true,
    }
  )
}

const query = computed(() => props.query)
const results = computed(() => props.results)
const searchEnabled = computed(() => props.searchEnabled)
</script>
