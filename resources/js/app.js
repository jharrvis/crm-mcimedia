import './bootstrap';
import { initDueDatePickers } from './due-date-picker';
import { initProductPickers } from './product-picker';

// Keduanya idempoten: due-date lewat atribut data-ready per input,
// picker produk lewat data-picker-bound per input baris item.
initDueDatePickers();
initProductPickers();

// Chart.js untuk halaman monitoring (di-expose global agar bisa dipakai inline module script)
import Chart from 'chart.js/auto';
import 'chartjs-adapter-date-fns';
window.Chart = Chart;
