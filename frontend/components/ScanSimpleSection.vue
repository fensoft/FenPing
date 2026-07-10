<template><div class="scan-section"><h3>{{ title }}</h3><table class="table table-sm scan-table"><tbody><slot /></tbody></table></div></template>
<script setup>defineProps({ title: { type: String, required: true } });</script>
