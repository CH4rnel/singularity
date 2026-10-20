shader_type spatial;
render_mode unshaded, cull_disabled, shadows_disabled;

uniform float phase = 0.0;
uniform float lock_amount : hint_range(0.0, 1.0) = 0.0;
uniform float visibility : hint_range(0.0, 1.0) = 1.0;
uniform vec2 parallax = vec2(0.0);

mat2 spin(float a) { return mat2(vec2(cos(a), -sin(a)), vec2(sin(a), cos(a))); }
float octagon(vec2 p) { p = abs(p); return max(max(p.x, p.y), (p.x + p.y) * .70710678); }
float vault(vec3 p) {
	p.xy = spin(sin(p.z * .25 + phase * .22) * .28 * (1.0 - lock_amount)) * p.xy;
	float z = mod(p.z + 1.5, 3.0) - 1.5;
	float rib = max(abs(octagon(p.xy) - 1.9) - .045, abs(z) - .065);
	float detail = max(abs(octagon(p.xy) - 2.07) - .02, abs(mod(p.z + .25, .5) - .25) - .025);
	return min(rib, detail) * .7;
}
void fragment() {
	vec2 uv = (UV - .5) * vec2(1.0, 1.3);
	vec3 ro = vec3(parallax * .3, -phase * .65);
	vec3 ray = normalize(vec3(uv, -1.35));
	float t = .01;
	float glow = 0.0;
	float surface = 0.0;
	for (int i = 0; i < 48; i++) {
		float d = vault(ro + ray * t);
		glow += exp(-abs(d) * 25.0) * .016;
		if (d < .002) { surface = exp(-t * .065); break; }
		t += max(d, .002);
		if (t > 36.0) { break; }
	}
	vec3 amber = mix(vec3(1.0, .35, .07), vec3(.18, 1.0, .65), lock_amount);
	vec3 color = amber * (surface * .85 + glow * .7);
	color += vec3(.015, .055, .055) * exp(-length(uv) * 2.0);
	float scan = .88 + .12 * sin(UV.y * 380.0 + phase * 2.0);
	ALBEDO = color * scan;
	EMISSION = color * .45;
	ALPHA = visibility;
}
