import './bootstrap';
import { initDueDatePickers } from './due-date-picker';
import { initProductPickers } from './product-picker';
import { initHestiaSync } from './hestia-sync';

// Semuanya idempoten: due-date lewat atribut data-ready per input, picker
// produk lewat data-picker-bound per baris item, dan sync Hestia hanya aktif
// di halaman yang memuat panel/tombolnya (t_dcccffd9).
initDueDatePickers();
initProductPickers();
initHestiaSync();

// Chart.js untuk halaman monitoring (di-expose global agar bisa dipakai inline module script)
import Chart from 'chart.js/auto';
import 'chartjs-adapter-date-fns';
window.Chart = Chart;
