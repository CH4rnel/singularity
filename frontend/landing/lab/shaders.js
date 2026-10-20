// Ray-marched signed-distance scenes. Both worlds share the same lighting and
// post-processing pipeline; no mesh assets, videos or pre-rendered backgrounds.
export const vertexShader = /* glsl */ `
varying vec2 vUv;
void main() { vUv = uv; gl_Position = vec4(position.xy, 0.0, 1.0); }
`;

export const fragmentShader = /* glsl */ `
precision highp float;
varying vec2 vUv;
uniform vec2 uResolution;
uniform vec2 uLook;
uniform vec2 uTouch;
uniform float uTime;
uniform float uFold;
uniform float uImpulse;
uniform float uZoom;
uniform float uTravel;
uniform float uMobile;
uniform float uFraming;
#define PI 3.14159265359
mat2 turn(float a) { float c=cos(a),s=sin(a);return mat2(c,-s,s,c); }
float box(vec3 p,vec3 b){vec3 q=abs(p)-b;return length(max(q,0.0))+min(max(q.x,max(q.y,q.z)),0.0);}
float smin(float a,float b,float k){float h=clamp(.5+.5*(b-a)/k,0.0,1.0);return mix(b,a,h)-k*h*(1.0-h);}
float oct(vec2 p){p=abs(p);return max(max(p.x,p.y),(p.x+p.y)*.70710678);}
vec2 nearest(vec2 a,vec2 b){return a.x<b.x?a:b;}

#if WORLD == 0
vec2 mapScene(vec3 p){
  vec3 q=p;
  q.xy=turn(sin(p.z*.16-uTime*.12)*uFold*.55+uImpulse*sin(p.z*.45)*.18)*q.xy;
  float z=mod(q.z+2.0,4.0)-2.0;
  float radius=oct(q.xy);
  // Large octagonal arches, inset luminous ribs and three nested detail scales.
  vec2 d=vec2(max(abs(radius-3.7)-.18,abs(z)-.21),1.0);
  d=nearest(d,vec2(max(abs(radius-3.47)-.025,abs(z)-.065),2.0));
  float smallZ=mod(q.z+.5,1.0)-.5;
  d=nearest(d,vec2(max(abs(radius-3.93)-.075,abs(smallZ)-.045),1.0));
  vec3 corners=vec3(abs(q.x)-2.73,abs(q.y)-2.73,0.0);
  d=nearest(d,vec2(box(corners,vec3(.11,.11,1000.0)),1.0));
  // Endless side galleries: smaller frames branch out beyond the main vault.
  vec3 gallery=vec3(abs(q.x)-4.3,q.y,mod(q.z+1.0,2.0)-1.0);
  float frame=max(abs(max(abs(gallery.y),abs(gallery.z))-1.35)-.065,abs(gallery.x)-.6);
  d=nearest(d,vec2(frame,1.0));
  // Recursively subdivided galleries: each tier is a 3x smaller frame lattice.
  float subdivision=1.0;
  for(int level=0;level<3;level++){
    vec3 cell=vec3(abs(q.x)-4.02,mod(q.y*subdivision+1.0,2.0)-1.0,mod(q.z*subdivision+1.0,2.0)-1.0);
    float edge=abs(max(abs(cell.y),abs(cell.z))-.87)/subdivision-.016/subdivision;
    float lattice=max(edge,abs(cell.x)-.045);
    d=nearest(d,vec2(lattice,1.0));
    subdivision*=3.0;
  }
  d=nearest(d,vec2(q.y+3.65,3.0));
  vec3 rail=vec3(abs(q.x)-1.15,q.y+3.61,0.0);
  d=nearest(d,vec2(box(rail,vec3(.018,.018,1000.0)),2.0));
  return vec2(d.x*.72,d.y);
}
#else
vec3 sculpt(vec3 p){
  p.xz=turn(uTime*.14+uLook.x*.45)*p.xz;
  p.yz=turn(.70+uLook.y*.3)*p.yz;
  p.xy=turn(.55+sin(uTime*.17)*.15)*p.xy;
  return p;
}
vec2 mapScene(vec3 p){
  vec3 q=sculpt(p);
  float a=atan(q.z,q.x);
  float major=1.35+.16*sin(a*3.0+uTime*.35)*(0.4+uFold);
  float tube=.48+uFold*.15;
  float ring=length(vec2(length(q.xz)-major,q.y)) - tube;
  vec3 b=vec3(cos(uTime*.41)*1.6,sin(uTime*.57)*.65,sin(uTime*.41)*1.5);
  float blob=length(q-b)-.73;
  float d=smin(ring,blob,.65);
  vec3 b2=vec3(cos(uTime*.31+PI)*1.6,sin(uTime*.37+2.0)*.75,sin(uTime*.31+PI)*1.6);
  d=smin(d,length(q-b2)-.57,.48);
  float ripple=sin(q.x*9.0+uTime*1.1)*sin(q.y*8.0-uTime*.8)*sin(q.z*9.0);
  d+=ripple*(.012+uFold*.036);
  float wave=sin(length(q.xy-uTouch*1.7)*13.0-uImpulse*7.0);
  d+=wave*uImpulse*.105;
  return vec2(d*.65,1.0);
}
#endif
vec3 normalAt(vec3 p){
  vec2 e=vec2(.0015,-.0015);
  return normalize(e.xyy*mapScene(p+e.xyy).x+e.yyx*mapScene(p+e.yyx).x+e.yxy*mapScene(p+e.yxy).x+e.xxx*mapScene(p+e.xxx).x);
}
float occlusion(vec3 p,vec3 n){
  float occ=0.0,weight=1.0;
  for(int i=1;i<=4;i++){float h=.07*float(i);occ+=(h-mapScene(p+n*h).x)*weight;weight*=.55;}
  return clamp(1.0-occ*2.0,.12,1.0);
}
vec3 studio(vec3 d){
  // Analytic HDR studio: broad softboxes and thin strips reflected by the metal.
  float az=atan(d.z,d.x),el=asin(clamp(d.y,-1.0,1.0));
  vec3 col=mix(vec3(.018,.025,.055),vec3(.20,.23,.32),smoothstep(-.45,.8,d.y));
  float softbox=(1.0-smoothstep(.28,.38,abs(az-1.2)))*(1.0-smoothstep(.65,.77,abs(el-.35)));
  float strip=(1.0-smoothstep(.045,.085,abs(az+1.6)))*(1.0-smoothstep(.9,1.15,abs(el)));
  float rim=(1.0-smoothstep(.15,.25,abs(az+.15)))*(1.0-smoothstep(.6,.8,abs(el+.2)));
  col+=vec3(2.8,2.65,2.5)*softbox;
  col+=vec3(2.0,1.5,3.0)*strip;
  col+=vec3(.6,1.3,1.7)*rim;
  col+=vec3(.4,.25,.12)*pow(max(0.0,-d.y),4.0);
  return col;
}
void main(){
  vec2 uv=(gl_FragCoord.xy-.5*uResolution)/uResolution.y;
  uv.x-=mix(.28,.02,uMobile)*uFraming;
  vec3 ro,ta;
#if WORLD == 0
  float travel=uTravel;
  ro=vec3(sin(uTime*.12)*.22+uLook.x*.8,.15+uLook.y*.7,travel+uZoom*2.0);
  ta=ro+vec3(uLook.x*.65,uLook.y*.6,-5.0);
#else
  uv.y+=uMobile*.16*uFraming;
  ro=vec3(0.0,.1,(9.2+uZoom)*mix(1.0,1.9,uMobile));
  ta=vec3(0.0,0.0,0.0);
#endif
  vec3 forward=normalize(ta-ro),right=normalize(cross(forward,vec3(0,1,0))),up=cross(right,forward);
  vec3 rd=normalize(forward*1.65+uv.x*right+uv.y*up);
  float t=.03,glow=0.0;
  vec2 hit=vec2(0.0);
  bool found=false;
  for(int i=0;i<MARCH_STEPS;i++){
    vec3 p=ro+rd*t;
    hit=mapScene(p);
#if WORLD == 0
    // Integrate light around the luminous ribs along the viewing ray.
    if(hit.y>1.5&&hit.y<2.5)glow+=exp(-abs(hit.x)*14.0)*.012;
#endif
    if(hit.x<.0015*(1.0+t*.08)){found=true;break;}
    t+=max(hit.x,.001);
    if(t>65.0)break;
  }
  vec3 col;
#if WORLD == 0
  vec3 fog=vec3(.012,.024,.032);
  col=fog;
  if(found){
    vec3 p=ro+rd*t,n=normalAt(p);
    float ao=occlusion(p,n);
    vec3 light=normalize(vec3(-.5,1.0,.35));
    float diffuse=max(0.0,dot(n,light));
    float fres=pow(1.0-max(0.0,dot(-rd,n)),3.0);
    vec3 base=vec3(.18,.23,.25);
    float detail=sin(p.z*25.0)*sin(p.y*21.0)*sin(p.x*21.0);
    base*=.85+detail*.15;
    col=base*(.16+diffuse*.7)*ao+vec3(.13,.28,.34)*fres*.4;
    if(hit.y>1.5&&hit.y<2.5)col=vec3(3.2,1.25,.35)*(1.0+uImpulse*1.5);
    if(hit.y>2.5){
      // Wet, engraved obsidian floor with moving amber reflections.
      float lines=pow(.5+.5*cos(p.z*PI*.5),24.0);
      float refl=exp(-abs(abs(p.x)-1.15)*1.1)*lines;
      col=vec3(.015,.027,.035)+vec3(.50,.20,.055)*refl+fres*vec3(.03,.08,.10);
      col*=ao;
    }
    col=mix(fog,col,exp(-t*.033));
  }
  col+=vec3(1.0,.34,.075)*glow*(1.0+uImpulse);
  // A cool distant light makes the otherwise endless depth readable.
  float vanishing=exp(-length(uv-vec2(uLook.x*.12,uLook.y*.12))*10.0);
  col+=vec3(.04,.25,.30)*vanishing;
#else
  col=vec3(.019,.023,.035);
  col+=vec3(.045,.04,.075)*exp(-length(uv)*1.8);
  // Sparse concentric etched rings underneath the sculpture.
  if(rd.y<-.01){
    float ft=(-2.1-ro.y)/rd.y;
    vec3 floorPoint=ro+rd*ft;
    float rr=length(floorPoint.xz);
    float rings=pow(.5+.5*cos(rr*8.0),80.0)*exp(-rr*.22);
    col+=vec3(.1,.09,.15)*rings*.35;
    col+=vec3(.13,.09,.22)*exp(-rr*rr*.13)*.3;
  }
  if(found){
    vec3 p=ro+rd*t,n=normalAt(p);
    vec3 reflected=reflect(rd,n);
    float fres=pow(1.0-max(0.0,dot(-rd,n)),5.0);
    float ao=occlusion(p,n);
    vec3 tint=mix(vec3(.78,.80,.90),vec3(.84,.66,.95),uFold*.45);
    col=studio(reflected)*tint*(.65+.35*ao);
    col+=studio(reflected+vec3(.025))*fres*.18;
    col+=vec3(.15,.12,.24)*fres;
    float spec=pow(max(0.0,dot(reflect(normalize(vec3(-2,3,4)),n),rd)),90.0);
    col+=vec3(1.5)*spec;
  }
#endif
  // ACES-like filmic shoulder, subtle vignette, deterministic dithering.
  col=max(col,vec3(0.0));
  col=(col*(2.51*col+.03))/(col*(2.43*col+.59)+.14);
  col=pow(clamp(col,0.0,1.0),vec3(1.0/2.2));
  col*=1.0-.22*dot(vUv-.5,vUv-.5);
  float noise=fract(sin(dot(gl_FragCoord.xy,vec2(12.9898,78.233)))*43758.5453);
  col+=(noise-.5)/255.0;
  gl_FragColor=vec4(col,1.0);
}
`;

export const postShader = /* glsl */ `
varying vec2 vUv;
uniform sampler2D uFrame;
uniform vec2 uTexel;
uniform float uImpulse;
void main(){
  vec2 dir=vUv-.5;
  float shift=.0006+uImpulse*.0025;
  // Edge-aware antialiasing before glow and chromatic lens separation.
  vec3 center=texture2D(uFrame,vUv).rgb;
  vec3 nw=texture2D(uFrame,vUv+vec2(-1.0,-1.0)*uTexel).rgb;
  vec3 ne=texture2D(uFrame,vUv+vec2(1.0,-1.0)*uTexel).rgb;
  vec3 sw=texture2D(uFrame,vUv+vec2(-1.0,1.0)*uTexel).rgb;
  vec3 se=texture2D(uFrame,vUv+vec2(1.0,1.0)*uTexel).rgb;
  vec3 luma=vec3(.299,.587,.114);
  float lnw=dot(nw,luma),lne=dot(ne,luma),lsw=dot(sw,luma),lse=dot(se,luma),lc=dot(center,luma);
  vec2 edge=vec2(-((lnw+lne)-(lsw+lse)),(lnw+lsw)-(lne+lse));
  float reduction=max((lnw+lne+lsw+lse)*.03125,.0078125);
  edge=clamp(edge/(min(abs(edge.x),abs(edge.y))+reduction),vec2(-6.0),vec2(6.0))*uTexel;
  vec3 aa=.5*(texture2D(uFrame,vUv+edge*(-1.0/6.0)).rgb+texture2D(uFrame,vUv+edge*(1.0/6.0)).rgb);
  vec3 smoothColor=aa*.5+.25*(texture2D(uFrame,vUv-edge*.5).rgb+texture2D(uFrame,vUv+edge*.5).rgb);
  float low=min(lc,min(min(lnw,lne),min(lsw,lse))),high=max(lc,max(max(lnw,lne),max(lsw,lse)));
  float ls=dot(smoothColor,luma);
  vec3 col=(ls<low||ls>high)?aa:smoothColor;
  col.r+=texture2D(uFrame,vUv+dir*shift).r-center.r;
  col.b+=texture2D(uFrame,vUv-dir*shift).b-center.b;
  vec3 bloom=vec3(0.0);
  for(int i=0;i<8;i++){
    float a=float(i)*.785398;
    vec2 offset=vec2(cos(a),sin(a))*uTexel*5.0;
    bloom+=max(texture2D(uFrame,vUv+offset).rgb-.72,0.0);
    bloom+=max(texture2D(uFrame,vUv+offset*2.5).rgb-.8,0.0)*.5;
  }
  gl_FragColor=vec4(col+bloom*.11,1.0);
}
`;
