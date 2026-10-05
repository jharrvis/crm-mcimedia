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

// Alpine.js untuk interaksi shell (sidebar collapse/off-canvas, dropdown).
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
Alpine.plugin(collapse);
window.Alpine = Alpine;
Alpine.start();

// Lucide: render ikon <i data-lucide="..."> jadi SVG. Hanya ikon yang benar-benar
// dipakai (lihat grep data-lucide) diimpor named agar bundle tidak meledak.
// Menambah ikon baru = tambahkan ke daftar ini.
import {
    createIcons,
    Activity,
    ArrowLeft,
    ArrowRight,
    Bell,
    Building2,
    ChevronDown,
    Eye,
    FileText,
    Folder,
    FolderKanban,
    Globe,
    History,
    Inbox,
    KeyRound,
    LayoutDashboard,
    LayoutGrid,
    ListChecks,
    LockKeyhole,
    LogOut,
    Mail,
    Moon,
    PackageOpen,
    PanelLeftClose,
    ReceiptText,
    RefreshCw,
    Repeat,
    Search,
    Server,
    Settings,
    ShieldAlert,
    ShieldCheck,
    Sun,
    Tags,
    UserCog,
    Users,
    Wrench,
    X,
} from 'lucide';

const iconSet = {
    Activity,
    ArrowLeft,
    ArrowRight,
    Bell,
    Building2,
    ChevronDown,
    Eye,
    FileText,
    Folder,
    FolderKanban,
    Globe,
    History,
    Inbox,
    KeyRound,
    LayoutDashboard,
    LayoutGrid,
    ListChecks,
    LockKeyhole,
    LogOut,
    Mail,
    Moon,
    PackageOpen,
    PanelLeftClose,
    ReceiptText,
    RefreshCw,
    Repeat,
    Search,
    Server,
    Settings,
    ShieldAlert,
    ShieldCheck,
    Sun,
    Tags,
    UserCog,
    Users,
    Wrench,
    X,
};

window.lucide = { createIcons, icons: iconSet };
// Ikon yang tidak ada di iconSet dilewati createIcons (ada error console, render
// tetap jalan) — pertahankan daftar import sinkron dengan pemakaian data-lucide.
const renderIcons = () => createIcons({ icons: iconSet });
document.addEventListener('DOMContentLoaded', renderIcons);
renderIcons();
