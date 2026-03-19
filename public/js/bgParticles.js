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

function animate() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    particles.forEach(p => {
        p.x += p.dx;
        p.y += p.dy;

        if (p.x < 0 || p.x > canvas.width) p.dx *= -1;
        if (p.y < 0 || p.y > canvas.height) p.dy *= -1;

        // Mouse interaction: attract particles
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
        ctx.arc(p.x, p.y, p.radius, 0, Math.PI*2, false);
        ctx.fillStyle = p.color;
        ctx.fill();
    });
    requestAnimationFrame(animate);
}
animate();

window.addEventListener('resize', () => {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
});
