<?php $rigpiWelcomeFirstName = trim((string) getUserField($tUserName, "FirstName")); ?>
<style>
#rigpiWelcomeOverlay {
	display: none;
	position: fixed;
	inset: 0;
	z-index: 12000;
	align-items: center;
	justify-content: center;
	padding: 18px;
	background: rgba(18, 25, 31, .78);
}
#rigpiWelcomeOverlay.visible { display: flex; }
.rigpi-welcome-card {
	position: relative;
	width: min(94vw, 610px);
	max-height: 94vh;
	overflow: auto;
	padding: 16px;
	border: 2px solid rgba(255,255,255,.82);
	border-radius: 20px;
	background: #3f3f3f;
	box-shadow: 0 18px 55px rgba(0,0,0,.48);
	color: #fff;
	text-align: center;
}
.rigpi-welcome-card h2 { margin: 0 42px 12px; font-size: 1.55rem; color: #fff; font-weight: 700; }
.rigpi-welcome-video {
	display: block;
	width: auto;
	max-width: 100%;
	height: min(68vh, 620px);
	margin: 0 auto 13px;
	border-radius: 14px;
	background: #151515;
}
.rigpi-welcome-close {
	position: absolute;
	top: 8px;
	right: 11px;
	border: 0;
	background: transparent;
	color: #fff;
	font-size: 2rem;
	line-height: 1;
	cursor: pointer;
}
.rigpi-welcome-actions {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}
.rigpi-welcome-language {
    position: relative;
    display: block;
    width: 190px;
    max-width: 100%;
    margin: 0 auto 12px;
}
.rigpi-welcome-language::before {
    content: "\1F310";
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
}
.rigpi-welcome-language::after {
    content: "\25BE";
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #9dc7e6;
    pointer-events: none;
}
.rigpi-welcome-language select {
    appearance: none;
    -webkit-appearance: none;
    box-sizing: border-box;
    width: 100%;
    min-height: 42px;
    padding: 8px 34px 8px 43px;
    border: 1px solid #9dc7e6;
    border-radius: 9px;
    background: #202b35;
    color: #fff;
    font: inherit;
    font-size: 1rem;
    cursor: pointer;
    color-scheme: dark;
}
.rigpi-welcome-language select:hover { background: #2b3946; }
.rigpi-welcome-language select:focus-visible {
    outline: 2px solid #9dc7e6;
    outline-offset: 3px;
}
.rigpi-welcome-language select option { background: #202b35; color: #fff; }
.rigpi-welcome-choice { margin: 0; font-size: 1rem; }
.rigpi-welcome-continue {
	min-width: 155px;
	padding: 8px 18px;
	border: 1px solid #9dc7e6;
	border-radius: 9px;
	background: #1677d2;
	color: #fff;
	font-size: 1rem;
	cursor: pointer;
}
.rigpi-welcome-play {
	display: none;
	width: 100%;
	margin: -3px 0 12px;
	padding: 8px;
	border: 1px solid #9dc7e6;
	border-radius: 9px;
	background: #222;
	color: #fff;
	cursor: pointer;
}
.rigpi-welcome-play.visible { display: block; }
@media (max-width: 600px) {
	#rigpiWelcomeOverlay { padding: 7px; }
	.rigpi-welcome-card { width: 97vw; padding: 10px; border-radius: 15px; }
	.rigpi-welcome-card h2 { font-size: 1.25rem; margin-bottom: 8px; }
	.rigpi-welcome-video { height: min(67vh, 570px); }
	.rigpi-welcome-actions { justify-content: center; }
}
.rigpi-welcome-credit { margin: 0 0 12px; color: #e0e0e0; font-size: 0.8rem; line-height: 1.4; text-align: center; }
</style>
<div id="rigpiWelcomeOverlay" role="dialog" aria-modal="true" aria-labelledby="rigpiWelcomeTitle">
	<div class="rigpi-welcome-card">
		<button class="rigpi-welcome-close" type="button" aria-label="Close welcome" onclick="closeRigPiWelcome()">&times;</button>
		<h2 id="rigpiWelcomeTitle"><?php echo htmlspecialchars("Welcome to RigPi" . ($rigpiWelcomeFirstName !== "" ? ", " . $rigpiWelcomeFirstName : "") . "!", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); ?></h2>
		<video id="rigpiWelcomeVideo" class="rigpi-welcome-video" controls playsinline preload="metadata">
			<source id="rigpiWelcomeSource" src="/Recordings/rigpi-welcome-en.mp4" type="video/mp4">
			<track src="/Recordings/rigpi-welcome-en.vtt" kind="captions" srclang="en" label="English" default>
			Your browser does not support video playback.
		</video>
<p class="rigpi-welcome-credit">Created with assistance from ChatGPT. AI-generated narration by ElevenLabs.</p>
		<button id="rigpiWelcomePlay" class="rigpi-welcome-play" type="button">Play welcome with sound</button>
		<label class="rigpi-welcome-language" for="rigpiWelcomeLanguage"><select id="rigpiWelcomeLanguage" aria-label="Welcome video language"><option value="en">English</option><option value="es">Espa&#241;ol</option><option value="fr">Fran&#231;ais</option><option value="de">Deutsch</option><option value="it">Italiano</option><option value="ja">&#26085;&#26412;&#35486;</option><option value="zh">&#20013;&#25991;</option><option value="sv">Svenska</option></select></label>
		<div class="rigpi-welcome-actions">
			<label class="rigpi-welcome-choice"><input id="rigpiWelcomeRemember" type="checkbox" checked> Don’t show this again</label>
			<button class="rigpi-welcome-continue" type="button" onclick="closeRigPiWelcome()">Continue to RigPi</button>
		</div>
	</div>
</div>
<script>
const rigpiWelcomeStorageKey = <?php echo json_encode(
    "rigpiWelcomeVideoV1:" . strtolower((string) $tUserName) . (is_readable("/var/lib/rigpi/welcome-generation") ? ":" . trim(file_get_contents("/var/lib/rigpi/welcome-generation")) : "")
); ?>;
const rigpiWelcomeLanguageKey = <?php echo json_encode(
    "rigpiWelcomeLanguage:" . strtolower((string) $tUserName)
); ?>;
const rigpiWelcomeLanguages = ['en', 'es', 'fr', 'de', 'it', 'ja', 'zh', 'sv'];

function preferredRigPiWelcomeLanguage() {
	const saved = localStorage.getItem(rigpiWelcomeLanguageKey);
	if (rigpiWelcomeLanguages.includes(saved)) return saved;
	const choices = Array.isArray(navigator.languages) && navigator.languages.length
		? navigator.languages : [navigator.language || 'en'];
	for (const choice of choices) {
		const primary = String(choice).toLowerCase().split('-')[0];
		if (rigpiWelcomeLanguages.includes(primary)) return primary;
	}
	return 'en';
}

function setRigPiWelcomeLanguage(language, playAfterLoad) {
	const lang = rigpiWelcomeLanguages.includes(language) ? language : 'en';
	const video = document.getElementById('rigpiWelcomeVideo');
	const source = document.getElementById('rigpiWelcomeSource');
	const selector = document.getElementById('rigpiWelcomeLanguage');
	const track = video ? video.querySelector('track') : null;
	if (!video || !source) return;
	selector.value = lang;
	localStorage.setItem(rigpiWelcomeLanguageKey, lang);
	const videoPath = '/Recordings/rigpi-welcome-' + lang + '.mp4';
	if (source.getAttribute('src') === videoPath && video.readyState > 0) {
		if (playAfterLoad) video.play();
		return;
	}
	source.setAttribute('src', videoPath);
	if (track) {
		track.setAttribute('src', '/Recordings/rigpi-welcome-' + lang + '.vtt');
		track.setAttribute('srclang', lang);
		track.setAttribute('label', selector.options[selector.selectedIndex].text);
	}
	video.load();
	if (playAfterLoad) {
		video.addEventListener('canplay', function playSelectedWelcome(){
			video.removeEventListener('canplay', playSelectedWelcome);
			video.play();
		}, { once: true });
	}
}

function showRigPiWelcome(forceReplay) {
	const overlay = document.getElementById('rigpiWelcomeOverlay');
	const video = document.getElementById('rigpiWelcomeVideo');
	const playButton = document.getElementById('rigpiWelcomePlay');
	if (!overlay || !video) return;
	if (!forceReplay && localStorage.getItem(rigpiWelcomeStorageKey) === 'hidden') return;
	overlay.classList.add('visible');
	document.body.style.overflow = 'hidden';
	video.volume = 0.5;
    video.currentTime = 0;
	const attempt = video.play();
	if (attempt && typeof attempt.catch === 'function') {
		attempt.then(function(){ playButton.classList.remove('visible'); })
			.catch(function(){ playButton.classList.add('visible'); });
	}
}

function closeRigPiWelcome() {
	const overlay = document.getElementById('rigpiWelcomeOverlay');
	const video = document.getElementById('rigpiWelcomeVideo');
	const remember = document.getElementById('rigpiWelcomeRemember');
	if (video) video.pause();
	if (overlay) overlay.classList.remove('visible');
	document.body.style.overflow = '';
	if (remember && remember.checked) localStorage.setItem(rigpiWelcomeStorageKey, 'hidden');
	else localStorage.removeItem(rigpiWelcomeStorageKey);
}

document.addEventListener('DOMContentLoaded', function(){
	const playButton = document.getElementById('rigpiWelcomePlay');
	const video = document.getElementById('rigpiWelcomeVideo');
	const overlay = document.getElementById('rigpiWelcomeOverlay');
	const language = document.getElementById('rigpiWelcomeLanguage');
	setRigPiWelcomeLanguage(preferredRigPiWelcomeLanguage(), false);
	if (language) language.addEventListener('change', function(){
		setRigPiWelcomeLanguage(language.value, true);
	});
	if (playButton && video) playButton.addEventListener('click', function(){
		video.play().then(function(){ playButton.classList.remove('visible'); });
	});
	if (overlay) overlay.addEventListener('click', function(event){
		if (event.target === overlay) closeRigPiWelcome();
	});
	showRigPiWelcome(new URLSearchParams(window.location.search).get('welcome') === '1');
});
</script>
