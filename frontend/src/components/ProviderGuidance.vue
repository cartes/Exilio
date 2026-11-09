<script setup>
import { ref, useTemplateRef } from 'vue'

// Mensaje del modelo cuando declina investigar un tema o propone cambiar de rumbo.
// Es información para el auditor: nada de lo que se muestra aquí se guarda como memoria
// y el cambio de tema solo ocurre si el auditor lo elige.
const emit = defineEmits(['adopt-topic'])

const dialog = useTemplateRef('dialog')
const guidance = ref(null)

function open(g) {
  guidance.value = g
  dialog.value?.showModal()
}

function adopt() {
  const topic = guidance.value?.suggestedTopic
  dialog.value?.close()
  if (topic) emit('adopt-topic', topic)
}

defineExpose({ open })
</script>

<template>
  <dialog ref="dialog" closedby="any" aria-labelledby="guidanceTitle"
    class="w-full max-w-lg rounded-3xl bg-slate-950/95 text-violet-50 border border-violet-300/15 p-0 backdrop:bg-black/70">
    <div v-if="guidance" class="p-6 space-y-5">
      <div>
        <h3 id="guidanceTitle" class="text-lg text-violet-100" style="font-family: ui-serif, Georgia, serif">
          {{ guidance.kind === 'decline' ? 'El modelo no pudo investigar esto' : 'El modelo sugiere otro rumbo' }}
        </h3>
        <p class="text-[11px] text-violet-200/50 mt-1">
          Tema: «{{ guidance.topic }}». No se guardó ninguna hipótesis ni cambió la versión.
        </p>
      </div>

      <p v-if="guidance.reason" class="text-sm leading-relaxed text-violet-100/90 whitespace-pre-line">{{ guidance.reason }}</p>

      <div v-if="guidance.suggestedTopic" class="rounded-xl bg-violet-500/5 border border-violet-300/10 p-3 text-xs space-y-1">
        <div class="text-[10px] uppercase tracking-widest text-violet-300/60">Tema sugerido</div>
        <div class="text-violet-100 text-sm">{{ guidance.suggestedTopic }}</div>
      </div>

      <div v-if="guidance.recommendations?.length" class="space-y-1.5">
        <div class="text-[10px] uppercase tracking-widest text-violet-300/60">Recomendaciones</div>
        <ul class="space-y-1 text-xs text-violet-100/85 list-disc pl-4">
          <li v-for="(r, i) in guidance.recommendations" :key="i">{{ r }}</li>
        </ul>
      </div>

      <div class="flex justify-end gap-3 pt-1">
        <button type="button" @click="dialog?.close()"
          class="px-4 py-1.5 rounded-full text-xs text-violet-200/70 hover:text-white transition">Mantener mi tema</button>
        <button v-if="guidance.suggestedTopic" type="button" @click="adopt"
          class="px-4 py-1.5 rounded-full text-xs bg-violet-500/30 hover:bg-violet-500/45 border border-violet-300/30 text-white transition">
          Investigar el tema sugerido
        </button>
      </div>
    </div>
  </dialog>
</template>
