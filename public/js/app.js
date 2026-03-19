/*********************
 * 1️⃣ Background Particles
 *********************/
const canvas = document.getElementById('bgCanvas');
const ctx = canvas.getContext('2d');
canvas.width = window.innerWidth;
canvas.height = window.innerHeight;

let mouse = { x: null, y: null };
window.addEventListener('mousemove', e => { mouse.x = e.clientX; mouse.y = e.clientY; });

let particles = [];
const particleCount = 120;

for (let i = 0; i < particleCount; i++) {
    particles.push({
        x: Math.random() * canvas.width,
        y: Math.random() * canvas.height,
        radius: Math.random() * 3 + 1,
        dx: (Math.random() - 0.5) * 0.5,
        dy: (Math.random() - 0.5) * 0.5,
        color: Math.random() > 0.5 ? '#FFD700' : '#fff'
    });
}

function animateBg() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    particles.forEach((p) => {
        p.x += p.dx;
        p.y += p.dy;

        if (p.x < 0 || p.x > canvas.width) p.dx *= -1;
        if (p.y < 0 || p.y > canvas.height) p.dy *= -1;

        if(mouse.x && mouse.y){
            let dx = p.x - mouse.x;
            let dy = p.y - mouse.y;
            let dist = Math.sqrt(dx*dx + dy*dy);
            if(dist < 100){
                p.x += dx/20;
                p.y += dy/20;
            }
        }

        ctx.beginPath();
        ctx.arc(p.x, p.y, p.radius, 0, Math.PI*2);
        ctx.fillStyle = p.color;
        ctx.fill();
    });
    requestAnimationFrame(animateBg);
}
animateBg();

window.addEventListener('resize', () => {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
});


/*********************
 * 2️⃣ Login Card Tilt
 *********************/
const card = document.getElementById('loginCard');

card.addEventListener('mousemove', (e) => {
    const rect = card.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;

    const centerX = rect.width / 2;
    const centerY = rect.height / 2;

    const rotateX = ((y - centerY) / centerY) * 8;
    const rotateY = ((x - centerX) / centerX) * -8;

    card.style.transform = `rotateX(${rotateX}deg) rotateY(${rotateY}deg) scale(1.03)`;
});

card.addEventListener('mouseleave', () => {
    card.style.transform = 'rotateX(0deg) rotateY(0deg) scale(1)';
});


/*********************
 * 3️⃣ Sparks on Input Typing
 *********************/
const sparkCanvas = document.createElement('canvas');
sparkCanvas.id = "sparkCanvas";
card.appendChild(sparkCanvas);
const sparkCtx = sparkCanvas.getContext('2d');

sparkCanvas.width = card.offsetWidth;
sparkCanvas.height = card.offsetHeight;

let sparkParticles = [];

function addSpark(x, y) {
    for (let i = 0; i < 5; i++) {
        sparkParticles.push({
            x: x,
            y: y,
            radius: Math.random() * 2 + 1,
            dx: (Math.random() - 0.5) * 2,
            dy: (Math.random() - 0.5) * 2,
            alpha: 1,
            color: Math.random() > 0.5 ? '#FFD700' : '#FFC300'
        });
    }
}

function animateSparks() {
    sparkCtx.clearRect(0, 0, sparkCanvas.width, sparkCanvas.height);

    sparkParticles.forEach((p, i) => {
        p.x += p.dx;
        p.y += p.dy;
        p.alpha -= 0.03;
        if (p.alpha <= 0) {
            sparkParticles.splice(i, 1);
        } else {
            sparkCtx.beginPath();
            sparkCtx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
            sparkCtx.fillStyle = `rgba(${p.color === '#FFD700' ? '255,215,0' : '255,195,0'},${p.alpha})`;
            sparkCtx.fill();
        }
    });

    requestAnimationFrame(animateSparks);
}
animateSparks();

// Trigger sparks on typing
const inputs = card.querySelectorAll('input');
inputs.forEach(input => {
    input.addEventListener('input', (e) => {
        const x = e.target.offsetLeft + e.target.offsetWidth / 2;
        const y = e.target.offsetTop + e.target.offsetHeight / 2;
        addSpark(x, y);
    });
});

// Resize spark canvas
window.addEventListener('resize', () => {
    sparkCanvas.width = card.offsetWidth;
    sparkCanvas.height = card.offsetHeight;
});


/*********************
 * 4️⃣ Login Button Logic (Demo)
 *********************/
document.getElementById('btnLogin').addEventListener('click', async () => {
    const user = document.getElementById('username').value.trim();
    const pass = document.getElementById('password').value.trim();
    const msg = document.getElementById('loginMsg');

    if (!user || !pass) {
        msg.textContent = "Please enter username and password.";
        return;
    }

    msg.textContent = "Checking credentials...";

    try {
        const response = await fetch("auth_login.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ username: user, password: pass })
        });

        const data = await response.json();

        if (data.success) {
            msg.style.color = "green";
            msg.textContent = "Login successful! Redirecting...";

            setTimeout(() => {
                window.location.href = "dashboard.php";
            }, 800);

        } else {
            msg.style.color = "red";
            msg.textContent = data.message;
        }

    } catch (err) {
        msg.textContent = "Network error!";
    }
});

